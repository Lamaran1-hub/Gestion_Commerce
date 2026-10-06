<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Devis;
use App\Models\Produit;
use App\Services\VenteService;
use Tests\TestCase;

/** Vente et réception par conditionnement (carton, casier…), stock tenu à l'unité. */
class ConditionnementTest extends TestCase
{
    private function eau($b, float $stock = 120): Produit
    {
        // Bouteille : achat 4 000, détail 5 000 ; carton de 12 vendu 54 000 (au lieu de 60 000)
        return $this->produit($b, ['designation' => 'Eau minérale', 'unite' => 'bouteille', 'prix_achat' => 4_000, 'prix_vente' => 5_000,
            'conditionnement' => 'carton', 'qte_conditionnement' => 12, 'prix_conditionnement' => 54_000], $stock);
    }

    public function test_vente_au_carton_sort_les_unites_du_stock_et_garde_une_marge_juste(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->eau($b);

        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [
            ['produit_id' => $p->id, 'quantite' => 2, 'conditionnement' => 1],
            ['produit_id' => $p->id, 'quantite' => 3],
        ], 'mode' => 'especes']), $admin);

        $this->assertSame(108_000 + 15_000, $v->total_ttc);
        $this->assertEquals(120 - 24 - 3, $p->fresh()->stock);
        $carton = $v->lignes->firstWhere('facteur', 12);
        $this->assertSame(54_000, $carton->prix_unitaire);
        $this->assertSame(48_000, $carton->prix_achat, 'coût du carton = 12 × 4 000');
        $this->assertStringContainsString('carton de 12 bouteille', $carton->designation);

        // Marge du jour : cartons (54 000 − 48 000) × 2 + bouteilles (5 000 − 4 000) × 3
        $this->actingAs($admin)->get('/tableau-de-bord?periode=jour')->assertSee('15 000 GNF');
    }

    public function test_annulation_et_retour_d_un_carton_remettent_les_unites(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->eau($b);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 3, 'conditionnement' => 1]], 'mode' => 'especes']), $admin);
        $this->assertEquals(84, $p->fresh()->stock);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Remboursez 54 000 GNF'));
        $this->assertEquals(96, $p->fresh()->stock);

        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])->assertSessionHas('succes');
        $this->assertEquals(120, $p->fresh()->stock);
    }

    public function test_pas_de_carton_sans_stock_suffisant(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->eau($b, 10); // moins d'un carton
        $this->actingAs($admin)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1, 'conditionnement' => 1]], 'mode' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Stock insuffisant'));
        $this->assertEquals(10, $p->fresh()->stock);
    }

    public function test_devis_au_carton_puis_vente(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->eau($b);
        $this->actingAs($admin)->post('/devis', ['lignes' => [['produit_id' => $p->id, 'quantite' => 5, 'conditionnement' => 1]]]);
        $d = $this->dans($b, fn () => Devis::first());
        $this->assertSame(270_000, $d->total_ttc);

        $this->post("/devis/{$d->id}/vente", ['mode' => 'especes'])->assertRedirect();
        $this->assertEquals(60, $p->fresh()->stock);
    }

    public function test_reception_au_carton(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->eau($b, 0);
        $this->actingAs($admin)->post('/approvisionnements', ['date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 10, 'prix_achat_unitaire' => '45 600', 'conditionnement' => 1]]])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertEquals(120, $p->fresh()->stock);
        $this->assertSame(3_800, $p->fresh()->prix_achat, '45 600 le carton ÷ 12');
        $this->assertSame(456_000, $this->dans($b, fn () => Approvisionnement::first()->total));
    }

    public function test_regles_du_conditionnement_sur_la_fiche_produit(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $base = ['designation' => 'Huile', 'unite' => 'bidon', 'prix_achat' => '4 000', 'prix_vente' => '5 000', 'conditionnement' => 'carton', 'qte_conditionnement' => 12];

        $this->actingAs($admin)->post('/produits', $base + ['prix_conditionnement' => '70 000'])->assertSessionHasErrors('prix_conditionnement');
        $this->post('/produits', $base + ['prix_conditionnement' => '40 000'])->assertSessionHasErrors('prix_conditionnement');
        $this->post('/produits', ['qte_conditionnement' => null] + $base)->assertSessionHasErrors('qte_conditionnement');
        $this->post('/produits', $base + ['prix_conditionnement' => '54 000'])->assertSessionHasNoErrors();
        $this->get('/caisse')->assertOk()->assertSee('"cond":"carton"', false);
    }
}
