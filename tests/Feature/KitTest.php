<?php

namespace Tests\Feature;

use App\Exceptions\OperationRefusee;
use App\Models\HistoriquePrix;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\Vente;
use App\Services\ApprovisionnementService;
use App\Services\RetourService;
use App\Services\StockService;
use App\Services\VenteService;
use Tests\TestCase;

/** Kits (packs composés) : vendre le kit sort ses composants ; stock et coût du kit suivent ses composants. */
class KitTest extends TestCase
{
    /** Pack rentrée = 1 sac (60 000 / achat 40 000) + 5 cahiers (achat 2 000) + 2 stylos (achat 500). */
    private function packRentree(): array
    {
        [$b, $admin] = $this->creerBoutique();
        $sac = $this->produit($b, ['designation' => 'Sac à dos', 'unite' => 'pièce', 'prix_achat' => 40_000, 'prix_vente' => 60_000], 10);
        $cahier = $this->produit($b, ['designation' => 'Cahier 200 p', 'unite' => 'pièce', 'prix_achat' => 2_000, 'prix_vente' => 3_000], 32);
        $stylo = $this->produit($b, ['designation' => 'Stylo bleu', 'unite' => 'pièce', 'prix_achat' => 500, 'prix_vente' => 1_000], 100);

        $this->actingAs($admin)->post('/produits', ['designation' => 'Pack rentrée', 'unite' => 'pack', 'prix_achat' => '0', 'prix_vente' => '75 000',
            'est_kit' => 1, 'stock_initial' => 50, 'conditionnement' => 'carton', 'qte_conditionnement' => 6,
            'composants' => [['produit_id' => $sac->id, 'quantite' => 1], ['produit_id' => $cahier->id, 'quantite' => 5], ['produit_id' => $stylo->id, 'quantite' => 2], ['produit_id' => '', 'quantite' => 1]],
        ])->assertSessionHasNoErrors()->assertRedirect();

        return [$b, $admin, $sac, $cahier, $stylo, $this->dans($b, fn () => Produit::where('designation', 'Pack rentrée')->sole())];
    }

    public function test_creation_d_un_kit_cout_et_stock_calcules(): void
    {
        [$b, $admin, $sac, $cahier, , $kit] = $this->packRentree();

        $this->assertTrue($kit->est_kit);
        $this->assertSame(40_000 + 5 * 2_000 + 2 * 500, $kit->prix_achat, 'coût = somme des composants');
        $this->assertEquals(6, $kit->stock, '32 cahiers / 5 = 6 kits : le cahier limite (le stock initial saisi est ignoré)');
        $this->assertNull($kit->conditionnement);
        $this->assertSame(0, MouvementStock::where('produit_id', $kit->id)->count());

        $this->get("/produits/{$kit->id}")->assertOk()->assertSee('Composition du kit')->assertSee('Kits formables')
            ->assertSee('Cahier 200 p')->assertSee("c'est lui qui limite", false)->assertDontSee('Approvisionner');
        $this->get("/produits/{$cahier->id}")->assertSee('Ce produit entre dans')->assertSee('Pack rentrée');
        $this->get("/produits/{$kit->id}/modifier")->assertOk()->assertSee('id="est_kit"', false)->assertSee('composants[0][produit_id]', false);

        // Le kit n'est compté ni dans la valeur du stock, ni dans l'inventaire, ni dans les réceptions
        $valeur = 10 * 40_000 + 32 * 2_000 + 100 * 500;
        $this->get('/produits')->assertSee(number_format($valeur, 0, ',', ' '));
        $this->get('/stock/inventaire')->assertOk()->assertSee('Cahier 200 p')->assertDontSee('Pack rentrée');
        $this->get('/approvisionnements/create')->assertOk()->assertDontSee('Pack rentrée');
    }

    public function test_vendre_annuler_et_reprendre_un_kit(): void
    {
        [$b, $admin, $sac, $cahier, $stylo, $kit] = $this->packRentree();

        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $kit->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $this->assertSame(150_000, $v->total_ttc);
        $this->assertSame([8.0, 22.0, 96.0], [$sac->fresh()->stock, $cahier->fresh()->stock, $stylo->fresh()->stock]);
        $this->assertEquals(4, $kit->fresh()->stock);
        $this->assertSame(51_000, $v->lignes->first()->prix_achat, 'marge du kit juste : coût des composants');
        $this->assertStringContainsString('kit Pack rentrée', MouvementStock::where('produit_id', $cahier->id)->latest('id')->value('motif'));

        // Pas plus de kits que de cahiers
        try {
            $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $kit->id, 'quantite' => 5]], 'mode' => 'especes']), $admin);
            $this->fail('vente de 5 kits acceptée avec 22 cahiers');
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('Cahier 200 p', $e->getMessage());
        }
        $this->assertSame(22.0, $cahier->fresh()->stock, 'rien n\'est sorti');

        // Retour d'un kit : ses composants reviennent
        $this->dans($b, fn () => app(RetourService::class)->enregistrer($v, [$v->lignes->first()->id => 1], 'Autre', 'especes', $admin), $admin);
        $this->assertSame([9.0, 27.0, 98.0], [$sac->fresh()->stock, $cahier->fresh()->stock, $stylo->fresh()->stock]);
        $this->assertEquals(5, $kit->fresh()->stock);

        // Annulation d'une autre vente de kit : tout revient
        $v2 = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $kit->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->dans($b, fn () => app(VenteService::class)->annuler(Vente::withoutGlobalScopes()->find($v2->id), 'Erreur'), $admin);
        $this->assertSame(27.0, $cahier->fresh()->stock);

        // Une réception de cahiers rend de nouveaux kits disponibles
        $this->dans($b, fn () => app(ApprovisionnementService::class)->creer(['date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $cahier->id, 'quantite' => 30, 'prix_achat_unitaire' => 2_000]]]), $admin);
        $this->assertEquals(9, $kit->fresh()->stock, 'le sac limite maintenant');
    }

    public function test_regles_du_kit(): void
    {
        [$b, $admin, $sac, $cahier, $stylo, $kit] = $this->packRentree();

        // Le stock d'un kit ne se réceptionne ni ne s'inventorie
        try {
            $this->dans($b, fn () => app(StockService::class)->mouvement($kit, 'approvisionnement', 5), $admin);
            $this->fail("réception d'un kit acceptée");
        } catch (OperationRefusee $e) {
            $this->assertStringContainsString('est un kit', $e->getMessage());
        }

        // Le coût d'un composant change : le coût du kit suit, avec son origine dans l'historique
        $this->dans($b, fn () => $cahier->update(['prix_achat' => 2_400]), $admin);
        $this->assertSame(53_000, $kit->fresh()->prix_achat);
        $this->assertSame('Coût des composants', $this->dans($b, fn () => HistoriquePrix::where('produit_id', $kit->id)->latest('id')->value('origine')));

        // Composant désactivé : plus de kit possible ; réactivé : de nouveau disponible
        $this->dans($b, fn () => $stylo->update(['actif' => false]), $admin);
        $this->assertEquals(0, $kit->fresh()->stock);
        $this->dans($b, fn () => $stylo->update(['actif' => true]), $admin);
        $this->assertEquals(6, $kit->fresh()->stock);

        // On ne supprime pas un produit qui entre dans un kit ; le kit, lui, se supprime même avec des kits formables
        $this->dans($b, fn () => app(StockService::class)->ajuster($stylo, 0, 'Test'), $admin);
        $this->actingAs($admin)->delete("/produits/{$stylo->id}")->assertSessionHas('erreur', fn ($m) => str_contains($m, 'entre dans le kit'));
        $this->assertNotSoftDeleted('produits', ['id' => $stylo->id]);

        // Pas de kit dans un kit ; pas de produit en stock transformé en kit
        $this->put("/produits/{$sac->id}", ['designation' => 'Sac à dos', 'unite' => 'pièce', 'prix_achat' => '40 000', 'prix_vente' => '60 000',
            'est_kit' => 1, 'composants' => [['produit_id' => $cahier->id, 'quantite' => 1]]])->assertSessionHasErrors('est_kit');
        $this->post('/produits', ['designation' => 'Super pack', 'unite' => 'pack', 'prix_achat' => '0', 'prix_vente' => '200 000',
            'est_kit' => 1, 'composants' => [['produit_id' => $kit->id, 'quantite' => 2]]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'lui-même un kit'));

        $this->delete("/produits/{$kit->id}")->assertSessionHas('succes');
        $this->assertSoftDeleted('produits', ['id' => $kit->id]);
    }

    public function test_un_kit_vendu_a_perte_est_refuse_et_redevient_produit_simple(): void
    {
        [$b, $admin, , , , $kit] = $this->packRentree();
        $composants = [['produit_id' => $kit->composants()->first()->composant_id, 'quantite' => 1]];

        $this->actingAs($admin)->put("/produits/{$kit->id}", ['designation' => 'Pack rentrée', 'unite' => 'pack', 'prix_achat' => '0', 'prix_vente' => '30 000',
            'est_kit' => 1, 'composants' => $composants])->assertSessionHasErrors('prix_vente');

        // Il redevient un produit simple : plus de composition, stock à zéro, à approvisionner normalement
        $this->put("/produits/{$kit->id}", ['designation' => 'Pack rentrée', 'unite' => 'pack', 'prix_achat' => '51 000', 'prix_vente' => '75 000', 'est_kit' => 0])
            ->assertSessionHasNoErrors();
        $kit = $kit->fresh();
        $this->assertFalse($kit->est_kit);
        $this->assertEquals(0, $kit->stock);
        $this->assertSame(0, $kit->composants()->count());
    }
}
