<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Vente;
use App\Services\VenteService;
use Tests\TestCase;

class LivraisonTest extends TestCase
{
    /** Vente de 2 × 300 000 = 600 000 GNF à Alpha Soumah, payée 200 000 (reste 400 000). */
    private function vente($b, $admin): Vente
    {
        $p = $this->produit($b, [], 20);
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Soumah', 'prenom' => 'Alpha', 'telephone' => '622000111', 'quartier' => 'Kaloum']));

        return $this->dans($b, fn () => app(VenteService::class)->creer([
            'client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes', 'montant_recu' => 200_000,
        ]), $admin);
    }

    public function test_cycle_complet_a_livrer_en_route_livree(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $v = $this->vente($b, $admin);
        $this->actingAs($admin)->get("/ventes/{$v->id}")->assertSee('Livrer cette vente chez le client')->assertSee('Kaloum');

        // Programmer : adresse obligatoire, date pas avant la vente
        $this->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => ''])->assertSessionHasErrors('livraison_adresse');
        $this->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => 'Kaloum', 'livraison_prevue_le' => now()->subDays(3)->toDateString()])
            ->assertSessionHasErrors('livraison_prevue_le');
        $this->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => 'Kaloum, près de la mosquée', 'livraison_contact' => 'Alpha 622 00 01 11',
            'livraison_prevue_le' => now()->toDateString()])->assertSessionHas('succes');
        $v->refresh();
        $this->assertSame('a_livrer', $v->livraison);

        $this->get('/livraisons')->assertOk()->assertSee($v->numero)->assertSee('près de la mosquée')->assertSee('400 000 GNF');
        $this->get('/tableau-de-bord')->assertSee('1 livraison(s) à faire aujourd');
        $this->get("/ventes/{$v->id}/bon-livraison")->assertOk()->assertHeader('content-type', 'application/pdf');

        // Départ puis livraison
        $this->post("/ventes/{$v->id}/livraison/depart", ['livreur' => ''])->assertSessionHasErrors('livreur');
        $this->post("/ventes/{$v->id}/livraison/depart", ['livreur' => 'Sékou'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Montant à encaisser à la livraison : 400 000 GNF'));
        $this->assertSame(['en_route', 'Sékou'], [$v->fresh()->livraison, $v->fresh()->livreur]);
        $this->get("/ventes/{$v->id}")->assertSee('Prévenir le client')->assertSee('wa.me', false);

        $this->post("/ventes/{$v->id}/livraison/livree", ['livree_a' => 'Mme Soumah'])->assertSessionHas('succes', fn ($m) => str_contains($m, 'Pensez à encaisser'));
        $v->refresh();
        $this->assertSame(['livree', 'Mme Soumah'], [$v->livraison, $v->livree_a]);
        $this->assertNotNull($v->livree_le);

        // Figée : plus de modification, plus d'annulation
        $this->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => 'Ailleurs'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà été livrée'));
        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'retour de marchandise'));
        $this->assertSame('validee', $v->fresh()->statut);
        $this->get('/livraisons')->assertDontSee($v->numero);
        $this->get('/livraisons?vue=livrees')->assertSee($v->numero)->assertSee('Mme Soumah');
    }

    public function test_livraison_retiree_et_vente_annulee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $v = $this->vente($b, $admin);
        $this->actingAs($admin)->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => 'Ratoma']);
        $this->post("/ventes/{$v->id}/livraison/retirer")->assertSessionHas('succes');
        $this->assertNull($v->fresh()->livraison);
        $this->get("/ventes/{$v->id}/bon-livraison")->assertNotFound();
        $this->post("/ventes/{$v->id}/livraison/livree", ['livree_a' => 'X'])->assertSessionHas('erreur');

        // Vente annulée : la livraison disparaît de la liste et ne peut plus avancer
        $this->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => 'Ratoma']);
        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Le client a renoncé'])->assertSessionHas('succes');
        $this->get('/livraisons');   // consomme le message flash (qui cite le numéro)
        $this->get('/livraisons')->assertDontSee($v->numero);
        $this->post("/ventes/{$v->id}/livraison/depart", ['livreur' => 'Sékou'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'annulée'));
    }

    public function test_retard_signale_et_isolation(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $v = $this->vente($b, $admin);
        $this->actingAs($admin)->post("/ventes/{$v->id}/livraison", ['livraison_adresse' => 'Matoto', 'livraison_prevue_le' => now()->toDateString()]);
        $v->refresh()->update(['livraison_prevue_le' => now()->subDays(2)]);   // la date prévue est passée
        $this->assertTrue($v->fresh()->livraisonEnRetard());
        $this->get('/livraisons')->assertSee('en retard');
        $this->get('/tableau-de-bord')->assertSee('dont 1 en retard');

        [, $admin2] = $this->creerBoutique('Autre boutique', 'autre@test.gn');
        $this->actingAs($admin2)->get("/ventes/{$v->id}/bon-livraison")->assertNotFound();
        $this->post("/ventes/{$v->id}/livraison/livree", ['livree_a' => 'X'])->assertNotFound();
    }
}
