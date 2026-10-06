<?php

namespace Tests\Feature;

use App\Exceptions\OperationRefusee;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Services\ApprovisionnementService;
use App\Services\VenteService;
use App\Support\Plateforme;
use Tests\TestCase;

/** Règles de gestion : remise, vente à perte, crédit, annulation, coût d'achat, licence. */
class ReglesMetierTest extends TestCase
{
    private function vendre($b, $admin, array $donnees, bool $prixLibre = false)
    {
        return $this->dans($b, fn () => app(VenteService::class)->creer($donnees, $prixLibre), $admin);
    }

    public function test_la_remise_est_plafonnee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['remise_max_pct' => 10, 'vente_a_perte' => true]);
        $p = $this->produit($b); // vendu 300 000

        $this->expectExceptionObject(new OperationRefusee('La remise dépasse le maximum autorisé par la boutique : 10 % du montant, soit 30 000 GNF au plus.'));
        $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'remise' => 40_000]);
    }

    public function test_pas_de_vente_sous_le_prix_d_achat(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['remise_max_pct' => null]);
        $p = $this->produit($b); // achat 250 000, vente 300 000

        try {
            $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1, 'prix_unitaire' => 200_000]]], true);
            $this->fail('prix sous le coût accepté');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString("sous son prix d'achat", $e->getMessage());
        }
        try {
            $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'remise' => 80_000]);
            $this->fail('remise sous le coût acceptée');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('Remise possible au plus : 50 000 GNF', $e->getMessage());
        }
        $this->assertEquals(10, $p->fresh()->stock, 'rien n\'est sorti du stock');

        // La boutique peut l'autoriser
        $b->update(['vente_a_perte' => true]);
        $v = $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'remise' => 80_000]);
        $this->assertSame(220_000, $v->total_ttc);
    }

    public function test_plafond_de_credit_du_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['plafond_credit_defaut' => 500_000]);
        $p = $this->produit($b);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Sow']));

        $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]);
        try {
            $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]);
            $this->fail('plafond dépassé accepté');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('Crédit encore possible : 200 000 GNF', $e->getMessage());
        }
        // Payer une partie ramène sous le plafond
        $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 100_000]);

        // Plafond personnel plus large pour un bon client
        $client->update(['plafond_credit' => 2_000_000]);
        $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]);
        $this->assertSame(800_000, $this->dans($b, fn () => $client->fresh()->soldeDu()));
    }

    public function test_client_en_retard_de_paiement_ne_prend_plus_a_credit(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['delai_credit_jours' => 30]);
        $p = $this->produit($b);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Diallo']));

        $this->travel(-40)->days();
        $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]);
        $this->travelBack();

        try {
            $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]);
            $this->fail('crédit accordé à un client en retard');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('a un crédit en retard', $e->getMessage());
        }
        // Au comptant, il peut toujours acheter
        $this->vendre($b->fresh(), $admin, ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);
    }

    public function test_annulation_limitee_dans_le_temps_sauf_administrateur(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['delai_annulation_heures' => 24]);
        $p = $this->produit($b);
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'K', 'email' => 'v@test.gn',
            'password' => 'secret123', 'role_id' => \App\Models\Role::where('nom', 'Gestionnaire')->value('id')]));
        $this->travel(-2)->days();
        $v = $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);
        $this->travelBack();

        $this->actingAs($vendeur)->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])
            ->assertSessionHas('erreur', "Cette vente date de plus de 24 h : seul l'administrateur de la boutique peut l'annuler.");
        $this->actingAs($admin)->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])
            ->assertSessionHas('succes', 'La vente '.$v->numero.' est annulée et le stock a été réintégré. Pensez à rembourser 300 000 GNF au client.');
    }

    public function test_cout_moyen_pondere_et_prix_de_vente_protege(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['methode_cout' => 'cmp']);
        $p = $this->produit($b); // 10 en stock à 250 000

        $this->dans($b->fresh(), fn () => app(ApprovisionnementService::class)->creer(['lignes' => [
            ['produit_id' => $p->id, 'quantite' => 10, 'prix_achat_unitaire' => 290_000],
        ]]), $admin);
        $this->assertSame(270_000, $p->fresh()->prix_achat, '(10×250 000 + 10×290 000) / 20');

        // Un coût qui dépasse le prix de vente exige un nouveau prix de vente
        try {
            $this->dans($b->fresh(), fn () => app(ApprovisionnementService::class)->creer(['lignes' => [
                ['produit_id' => $p->id, 'quantite' => 100, 'prix_achat_unitaire' => 400_000],
            ]]), $admin);
            $this->fail('produit rendu invendable');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('Indiquez un nouveau prix de vente', $e->getMessage());
        }
        $this->assertEquals(20, $p->fresh()->stock);
    }

    public function test_produit_avec_stock_non_supprimable_et_prix_de_vente_coherent(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);
        $this->actingAs($admin)->delete("/produits/{$p->id}")->assertSessionHas('erreur');
        $this->assertNotSoftDeleted($p);

        $this->post('/produits', ['designation' => 'Huile', 'unite' => 'bidon', 'prix_achat' => '100 000', 'prix_vente' => '90 000'])
            ->assertSessionHasErrors('prix_vente');
    }

    public function test_un_numero_de_telephone_par_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->post('/clients', ['nom' => 'Camara', 'telephone' => '622 00 00 01'])->assertRedirect();
        $this->post('/clients', ['nom' => 'Autre', 'telephone' => '622 00 00 01'])->assertSessionHasErrors('telephone');
        [$b2, $admin2] = $this->creerBoutique('B2', 'b2@test.gn');
        $this->actingAs($admin2)->post('/clients', ['nom' => 'Camara', 'telephone' => '622 00 00 01'])->assertSessionHasNoErrors();
    }

    public function test_delai_de_grace_apres_l_echeance(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['abonnement_expire_le' => now()->subDays(2)->toDateString()]);
        $this->actingAs($admin)->get('/produits')->assertRedirect(route('abonnement'));

        Plateforme::enregistrer(['jours_grace' => '5']);
        $this->actingAs($admin->fresh())->get('/produits')->assertOk()->assertSee('Délai de grâce');
    }

    public function test_formule_trop_petite_refusee_au_paiement(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $petite = \App\Models\Plan::create(['nom' => 'Mini', 'prix_mensuel' => 50_000, 'max_utilisateurs' => 1, 'actif' => true]);
        $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'X', 'nom' => 'Y', 'email' => 'x@test.gn', 'password' => 'secret123']));
        $moi = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);

        $this->actingAs($moi)->post("/admin/boutiques/{$b->id}/paiements", [
            'plan_id' => $petite->id, 'duree' => '1', 'montant' => '50 000', 'mode' => 'especes', 'paye_le' => now()->toDateString(),
        ])->assertSessionHas('erreur');
        $this->assertSame(0, \App\Models\PaiementLicence::count());
    }

    public function test_nom_et_logo_du_logiciel_choisis_par_le_proprietaire(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $moi = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);
        $this->actingAs($moi)->put('/admin/parametres', [
            'nom_logiciel' => 'Caisse Plus', 'societe' => 'Ma Société SARL', 'jours_grace' => 3, 'jours_essai' => 30,
            'logo_logiciel' => \Illuminate\Http\UploadedFile::fake()->image('logo.png'),
        ])->assertSessionHas('succes');

        // Au démarrage suivant, le nom et la durée d'essai choisis remplacent les valeurs par défaut
        (new \App\Providers\AppServiceProvider($this->app))->boot();
        $this->assertSame('Caisse Plus', config('app.name'));
        $this->assertSame(30, config('gestion.jours_essai'));
        $this->post('/deconnexion');
        $this->get('/connexion')->assertSee('Caisse Plus')->assertSee('/storage/plateforme/', false);
    }
}
