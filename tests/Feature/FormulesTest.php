<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\Plan;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\VenteService;
use Tests\TestCase;

/** Formules : fonctions incluses, limites, conditions particulières accordées par le propriétaire. */
class FormulesTest extends TestCase
{
    private function proprietaire(): User
    {
        return User::create(['prenom' => 'Éditeur', 'nom' => 'Logiciel', 'email' => 'editeur@test.gn', 'password' => 'secret123', 'est_super_admin' => true]);
    }

    public function test_fonctions_non_incluses_dans_demarrage(): void
    {
        [$b, $admin] = $this->creerBoutique(toutesFonctions: false); // formule Démarrage, sans dérogation

        // Pages réservées : explication et formules qui les incluent (pas une erreur brute)
        $this->actingAs($admin)->get('/promotions')->assertForbidden()->assertSee('pas incluse')->assertSee('Commerce');
        $this->get('/tresorerie')->assertForbidden();
        $this->get('/produits/import')->assertForbidden();
        $this->get('/rapports/vendeurs/ecran')->assertForbidden()->assertSee('Rapports avancés');
        $this->get('/rapports/ventes/ecran')->assertOk(); // rapport de base toujours inclus
        // Fonctions incluses dans Démarrage
        $this->get('/devis')->assertOk();
        $this->get('/caisse')->assertOk()->assertSee('data-hors-ligne="1"', false);
        // Menu : pas de lien vers ce qui n'est pas inclus
        $this->get('/tableau-de-bord')->assertDontSee('/promotions')->assertDontSee('/tresorerie');
        $this->get('/rapports')->assertSee('formule supérieure');

        // Une promotion enregistrée avant un changement de formule ne s'applique plus ; la fidélité non plus
        $p = $this->produit($b, [], 10);
        $this->dans($b, fn () => Promotion::create(['nom' => 'Vieille promo', 'type' => 'pourcentage', 'valeur' => 10, 'produit_id' => $p->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(), 'actif' => true]));
        $b->update(['fidelite_taux' => 2]);
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Diallo']));
        $v = $this->dans($b->fresh(), fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->assertSame(300_000, $v->total_ttc);
        $this->assertSame(0, $c->fresh()->points);
    }

    public function test_factures_proforma_toujours_incluses(): void
    {
        [$b, $admin] = $this->creerBoutique(toutesFonctions: false);
        $p = $this->produit($b, [], 10);

        $this->actingAs($admin)->get('/devis')->assertOk()->assertSee('Nouvelle facture proforma');
        $this->get('/caisse?proforma=1')->assertOk()->assertSee('Enregistrer la proforma')->assertSee('nom du client / de la société');
        // Client non enregistré (société) : nom libre ; rien ne sort du stock
        $this->post('/devis', ['client_nom' => 'SOGECO SARL', 'note' => 'Livraison sous 48 h', 'lignes' => [['produit_id' => $p->id, 'quantite' => 3]]])
            ->assertRedirect()->assertSessionHas('succes');
        $d = $this->dans($b, fn () => \App\Models\Devis::first());
        $this->assertSame(900_000, $d->total_ttc);
        $this->assertSame('SOGECO SARL', $d->nomClient());
        $this->assertEquals(10, $p->fresh()->stock);
        $this->get("/devis/{$d->id}/proforma")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_le_proprietaire_accorde_des_conditions_particulieres(): void
    {
        [$b, $admin] = $this->creerBoutique(toutesFonctions: false);
        $moi = $this->proprietaire();

        // Trésorerie accordée en plus, 5 utilisateurs au lieu de 2, produits illimités (0)
        $this->actingAs($moi)->post("/admin/boutiques/{$b->id}/derogations", ['fonctions' => ['tresorerie', 'relances'],
            'max_utilisateurs' => 5, 'max_produits' => 0, 'motif' => 'Geste commercial'])->assertSessionHas('succes');
        $b = $b->fresh();
        $this->assertSame(['max_utilisateurs' => 5, 'max_produits' => 0, 'fonctions' => ['tresorerie']], $b->derogations, 'les relances sont déjà dans la formule');
        $this->assertSame(5, $b->limite('utilisateurs'));
        $this->assertNull($b->limite('produits'));
        $this->assertSame(1, $b->limite('boutiques'));
        $this->assertTrue(JournalActivite::withoutGlobalScopes()->where('boutique_id', $b->id)->where('description', 'like', '%Geste commercial%')->exists());
        $this->get("/admin/boutiques/{$b->id}")->assertOk()->assertSee('Limites et fonctions de ce client');

        $this->actingAs($admin->fresh())->get('/tresorerie')->assertOk();
        $this->get('/abonnement')->assertOk()->assertSee('Ma formule : utilisation')->assertSee('1 / 5')->assertSee('Comparer les formules');

        // Retour aux conditions standard
        $this->actingAs($moi)->post("/admin/boutiques/{$b->id}/derogations", []);
        $this->assertNull($b->fresh()->derogations);
    }

    public function test_limite_utilisateurs_aussi_a_la_reactivation(): void
    {
        [$b, $admin] = $this->creerBoutique(); // Démarrage : 2 utilisateurs actifs
        $role = $this->dans($b, fn () => Role::where('nom', 'Vendeur')->first());
        $champs = fn ($email) => ['prenom' => 'V', 'nom' => 'Vendeur', 'email' => $email, 'role_id' => $role->id, 'password' => 'Secret123!', 'password_confirmation' => 'Secret123!', 'actif' => 1];

        $this->actingAs($admin)->post('/utilisateurs', $champs('v1@test.gn'))->assertRedirect('/utilisateurs');
        $this->post('/utilisateurs', $champs('v2@test.gn'))->assertSessionHas('erreur', fn ($m) => str_contains($m, 'limitée à 2'));
        // On désactive v1, on crée v2, puis on tente de réactiver v1 : refusé
        $v1 = User::where('email', 'v1@test.gn')->first();
        $this->delete("/utilisateurs/{$v1->id}");
        $this->post('/utilisateurs', $champs('v2@test.gn'))->assertRedirect('/utilisateurs');
        $this->put("/utilisateurs/{$v1->id}", $champs('v1@test.gn'))->assertSessionHas('erreur', fn ($m) => str_contains($m, 'limitée à 2'));
        $this->assertFalse($v1->fresh()->actif);
    }

    public function test_le_proprietaire_regle_les_fonctions_d_une_formule(): void
    {
        $moi = $this->proprietaire();
        $demarrage = Plan::where('nom', 'Démarrage')->first();
        $donnees = ['nom' => 'Démarrage', 'prix_mensuel' => '150 000', 'max_utilisateurs' => 3, 'max_produits' => 500, 'max_boutiques' => 1, 'actif' => 1];

        $this->actingAs($moi)->put("/admin/plans/{$demarrage->id}", $donnees + ['fonctions' => ['relances', 'promotions']])->assertRedirect();
        $this->assertSame(['relances', 'promotions'], $demarrage->fresh()->fonctions);
        $this->assertSame(3, $demarrage->fresh()->max_utilisateurs);
        $this->put("/admin/plans/{$demarrage->id}", $donnees + ['toutes_fonctions' => 1]);
        $this->assertNull($demarrage->fresh()->fonctions);
        $this->assertTrue($demarrage->fresh()->inclut('tresorerie'));
        $this->get('/admin/plans')->assertOk()->assertSee('Toutes');
        $this->get("/admin/plans/{$demarrage->id}/edit")->assertOk()->assertSee('Fonctions incluses');
    }
}
