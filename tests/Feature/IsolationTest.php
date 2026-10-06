<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Produit;
use App\Services\VenteService;
use Tests\TestCase;

/** Une boutique ne doit jamais voir ni modifier les données d'une autre. */
class IsolationTest extends TestCase
{
    public function test_les_listes_ne_montrent_que_les_donnees_de_la_boutique(): void
    {
        [$a, $adminA] = $this->creerBoutique('Boutique A', 'a@test.gn');
        [$b, $adminB] = $this->creerBoutique('Boutique B', 'b@test.gn');
        $this->produit($a, ['designation' => 'Produit secret de A']);
        $this->produit($b, ['designation' => 'Produit de B']);

        $this->actingAs($adminB)->get('/produits')
            ->assertOk()->assertSee('Produit de B')->assertDontSee('Produit secret de A');
    }

    public function test_une_fiche_d_une_autre_boutique_renvoie_404(): void
    {
        [$a, $adminA] = $this->creerBoutique('Boutique A', 'a@test.gn');
        [$b, $adminB] = $this->creerBoutique('Boutique B', 'b@test.gn');
        $produitA = $this->produit($a);
        $venteA = $this->dans($a, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $produitA->id, 'quantite' => 1]]]), $adminA);
        $clientA = $this->dans($a, fn () => Client::create(['nom' => 'Client A']));

        $this->actingAs($adminB);
        $this->get("/produits/{$produitA->id}")->assertNotFound();
        $this->get("/produits/{$produitA->id}/modifier")->assertNotFound();
        $this->get("/ventes/{$venteA->id}")->assertNotFound();
        $this->get("/ventes/{$venteA->id}/facture")->assertNotFound();
        $this->get("/clients/{$clientA->id}")->assertNotFound();
        $this->delete("/produits/{$produitA->id}")->assertNotFound();
        $this->assertNull(Produit::withTrashed()->withoutGlobalScopes()->find($produitA->id)->deleted_at, 'le produit de A ne doit pas être supprimé');
    }

    public function test_une_vente_ne_peut_pas_utiliser_le_produit_d_une_autre_boutique(): void
    {
        [$a] = $this->creerBoutique('Boutique A', 'a@test.gn');
        [$b, $adminB] = $this->creerBoutique('Boutique B', 'b@test.gn');
        $produitA = $this->produit($a, [], 10);

        $this->actingAs($adminB)->post('/ventes', [
            'lignes' => [['produit_id' => $produitA->id, 'quantite' => 2]], 'mode' => 'especes',
        ])->assertSessionHas('erreur');

        $this->assertEquals(10, Produit::withoutGlobalScopes()->find($produitA->id)->stock);
    }

    public function test_la_numerotation_est_propre_a_chaque_boutique(): void
    {
        [$a, $adminA] = $this->creerBoutique('Boutique A', 'a@test.gn');
        [$b, $adminB] = $this->creerBoutique('Boutique B', 'b@test.gn');
        $pa = $this->produit($a);
        $pb = $this->produit($b);
        $service = app(VenteService::class);

        $v1 = $this->dans($a, fn () => $service->creer(['lignes' => [['produit_id' => $pa->id, 'quantite' => 1]]]), $adminA);
        $v2 = $this->dans($a, fn () => $service->creer(['lignes' => [['produit_id' => $pa->id, 'quantite' => 1]]]), $adminA);
        $v3 = $this->dans($b, fn () => $service->creer(['lignes' => [['produit_id' => $pb->id, 'quantite' => 1]]]), $adminB);

        $annee = now()->year;
        $this->assertSame("V-{$annee}-00001", $v1->numero);
        $this->assertSame("V-{$annee}-00002", $v2->numero);
        $this->assertSame("V-{$annee}-00001", $v3->numero);
    }
}
