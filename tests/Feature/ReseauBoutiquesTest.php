<?php

namespace Tests\Feature;

use App\Models\Boutique;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\Role;
use App\Models\Transfert;
use App\Models\User;
use Tests\TestCase;

/** Plusieurs boutiques pour un même commerçant : création, changement de boutique, isolation, transferts de stock. */
class ReseauBoutiquesTest extends TestCase
{
    /** Boutique d'origine (avec un produit) + nouveau point de vente créé par l'administrateur. */
    private function reseau(): array
    {
        [$a, $admin] = $this->creerBoutique('Boutique Kaloum');
        $a->update(['plan_id' => Plan::where('nom', 'Commerce')->value('id')]); // jusqu'à 3 boutiques
        $riz = $this->produit($a, ['code_barre' => 'RIZ25'], 20);
        $this->actingAs($admin)->post('/mes-boutiques', ['nom' => 'Boutique Madina', 'ville' => 'Conakry', 'copier_catalogue' => 1])
            ->assertRedirect('/tableau-de-bord');
        $b = Boutique::where('nom', 'Boutique Madina')->first();

        return [$a->fresh(), $b, $admin->fresh(), $riz];
    }

    public function test_nouveau_point_de_vente_et_changement_de_boutique(): void
    {
        [$a, $b, $admin, $riz] = $this->reseau();
        $this->assertNotNull($a->entreprise_id);
        $this->assertSame($a->entreprise_id, $b->entreprise_id);
        $this->assertSame('essai', $b->statut, 'licence propre au point de vente');
        $this->assertSame($a->couleur, $b->couleur);

        // Catalogue copié sans stock ; la nouvelle boutique est ouverte
        $copie = $this->dans($b, fn () => Produit::where('code_barre', 'RIZ25')->first());
        $this->assertEquals(0, $copie->stock);
        $this->assertSame($riz->prix_vente, $copie->prix_vente);
        $this->get('/produits')->assertSee('Riz 25 kg')->assertSee('Changer de boutique');

        // Retour à la première boutique
        $this->post("/mes-boutiques/{$a->id}/ouvrir")->assertRedirect();
        $this->get('/mes-boutiques')->assertOk()->assertSee('Boutique Madina')->assertSee('Ventes du jour (réseau)');
    }

    public function test_isolation_employes_et_autres_commercants(): void
    {
        [$a, $b, $admin] = $this->reseau();
        // Un vendeur de la boutique A ne peut pas ouvrir B ; un autre commerçant non plus
        $vendeur = $this->dans($a, fn () => User::create(['boutique_id' => $a->id, 'role_id' => Role::where('nom', 'Vendeur')->first()->id,
            'prenom' => 'V', 'nom' => 'Vendeur', 'email' => 'v@test.gn', 'password' => 'secret123', 'actif' => true]));
        $this->actingAs($vendeur)->post("/mes-boutiques/{$b->id}/ouvrir")->assertForbidden();
        $this->get('/mes-boutiques')->assertForbidden();

        [$autre, $autreAdmin] = $this->creerBoutique('Concurrent', 'autre@test.gn');
        $this->actingAs($autreAdmin)->post("/mes-boutiques/{$b->id}/ouvrir")->assertForbidden();
        // Une session trafiquée ne donne pas accès non plus
        $this->withSession(['boutique_active' => $b->id])->get('/produits')->assertDontSee('Riz 25 kg');
    }

    public function test_transfert_envoye_puis_recu_avec_manque(): void
    {
        [$a, $b, $admin, $riz] = $this->reseau();
        $this->post("/mes-boutiques/{$a->id}/ouvrir");
        $huile = $this->produit($a, ['designation' => 'Huile 5 L', 'prix_achat' => 80_000, 'prix_vente' => 95_000], 10); // absente de B

        $this->post('/transferts', ['boutique_destination_id' => $b->id, 'lignes' => [
            ['produit_id' => $riz->id, 'quantite' => 5], ['produit_id' => $huile->id, 'quantite' => 4]]])->assertRedirect();
        $t = Transfert::first();
        $this->assertSame('envoye', $t->statut);
        $this->assertEquals(15, $riz->fresh()->stock, 'le stock sort à l\'envoi');
        $this->assertSame(5 * 250_000 + 4 * 80_000, $t->valeur);
        // Pas plus que le stock
        $this->post('/transferts', ['boutique_destination_id' => $b->id, 'lignes' => [['produit_id' => $riz->id, 'quantite' => 50]]])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Stock insuffisant'));
        // La boutique de départ ne peut pas réceptionner à la place de l'autre
        $ligneRiz = $t->lignes->firstWhere('produit_source_id', $riz->id);
        $this->post("/transferts/{$t->id}/recevoir", ['recues' => [$ligneRiz->id => 5]])->assertSessionHas('erreur');

        // Réception dans B : 4 sacs de riz arrivés sur 5, l'huile au complet (produit créé)
        $this->post("/mes-boutiques/{$b->id}/ouvrir");
        $this->get('/transferts')->assertSee('À réceptionner');
        $ligneHuile = $t->lignes->firstWhere('produit_source_id', $huile->id);
        $this->post("/transferts/{$t->id}/recevoir", ['recues' => [$ligneRiz->id => 4, $ligneHuile->id => 4]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'perte en transit'));
        $rizB = $this->dans($b, fn () => Produit::where('code_barre', 'RIZ25')->first());
        $huileB = $this->dans($b, fn () => Produit::where('designation', 'Huile 5 L')->first());
        $this->assertEquals(4, $rizB->stock);
        $this->assertEquals(4, $huileB->stock);
        $this->assertSame(95_000, $huileB->prix_vente);
        $this->assertSame('recu', $t->fresh()->statut);
        $this->get('/rapports/pertes/ecran')->assertSee('Perte en transit')->assertSee('-250 000 GNF');
        // Déjà reçu : ni réception, ni annulation
        $this->post("/transferts/{$t->id}/recevoir", ['recues' => [$ligneRiz->id => 1]])->assertSessionHas('erreur');
    }

    public function test_limite_de_boutiques_selon_la_formule(): void
    {
        // Démarrage : une seule boutique
        [$a, $admin] = $this->creerBoutique('Petite boutique');
        $this->actingAs($admin)->get('/mes-boutiques')->assertOk()->assertSee('1 / 1 point(s) de vente')->assertSee('permet 1 boutique');
        $this->post('/mes-boutiques', ['nom' => 'Deuxième'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'formule supérieure'));
        $this->assertSame(1, Boutique::count());

        // Commerce : 3 boutiques, pas une de plus
        $a->update(['plan_id' => Plan::where('nom', 'Commerce')->value('id')]);
        $this->actingAs($admin->fresh()); // en production l'utilisateur et sa boutique sont relus à chaque requête
        $this->post('/mes-boutiques', ['nom' => 'Deuxième'])->assertSessionHas('succes');
        $this->post("/mes-boutiques/{$a->id}/ouvrir");
        $this->post('/mes-boutiques', ['nom' => 'Troisième'])->assertSessionHas('succes');
        $this->post("/mes-boutiques/{$a->id}/ouvrir");
        $this->post('/mes-boutiques', ['nom' => 'Quatrième'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'permet 3'));
        $this->assertSame(3, Boutique::count());

        // Revenir à Démarrage n'est plus possible : le réseau dépasse la formule
        $demarrage = Plan::where('nom', 'Démarrage')->first();
        $this->assertContains('3 boutiques dans le réseau (maximum 1)', $a->fresh()->depassementsFormule($demarrage));
        // Les boutiques secondaires ne sont pas jugées sur ce critère (leur licence est propre)
        $secondaire = Boutique::where('nom', 'Deuxième')->first();
        $this->assertSame([], array_filter($secondaire->depassementsFormule($demarrage), fn ($d) => str_contains($d, 'boutiques')));

        // Licence de la boutique principale expirée : pas de nouveau point de vente
        $a->update(['plan_id' => Plan::where('nom', 'Entreprise')->value('id'), 'abonnement_expire_le' => now()->subMonths(2)]);
        $this->actingAs($admin->fresh());
        $this->post('/mes-boutiques', ['nom' => 'Cinquième'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'renouvelez'));
    }

    public function test_annulation_avant_reception(): void
    {
        [$a, $b, $admin, $riz] = $this->reseau();
        $this->post("/mes-boutiques/{$a->id}/ouvrir");
        $this->post('/transferts', ['boutique_destination_id' => $b->id, 'lignes' => [['produit_id' => $riz->id, 'quantite' => 5]]]);
        $t = Transfert::first();
        $this->post("/transferts/{$t->id}/annuler")->assertSessionHas('succes');
        $this->assertEquals(20, $riz->fresh()->stock);
        $this->assertSame('annule', $t->fresh()->statut);
    }
}
