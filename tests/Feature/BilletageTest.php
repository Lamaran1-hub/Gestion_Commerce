<?php

namespace Tests\Feature;

use App\Models\CarteCadeau;
use App\Models\ClotureCaisse;
use App\Services\VenteService;
use Tests\TestCase;

/** Clôture : comptage billet par billet, et cartes cadeaux hors du total encaissé du rapport Z. */
class BilletageTest extends TestCase
{
    public function test_le_comptage_des_billets_fait_foi_et_figure_sur_le_rapport_z(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);   // 300 000 l'unité
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);

        $this->actingAs($admin)->get('/caisse/cloture')->assertOk()->assertSee('Compter billet par billet')->assertSee('Billet (GNF)')->assertSee('id="billet20000"', false)->assertSee('id="billet500"', false);

        // 14 × 20 000 + 2 × 10 000 = 300 000 : le total des billets remplace le montant saisi (même s'il est faux)
        $this->post('/caisse/cloture', ['especes_comptees' => '1', 'billetage' => ['20000' => 14, '10000' => 2, '5000' => '', '777' => 50]])
            ->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->first();
        $this->assertSame(300_000, $c->especes_comptees);
        $this->assertSame(0, $c->ecart);
        $this->assertEquals([20000 => 14, 10000 => 2], $c->billetage, 'coupures inconnues et lignes vides ignorées');
        $this->get("/clotures/{$c->id}")->assertOk()->assertSee('14 × 20 000 GNF')->assertSee('280 000 GNF')->assertSee('Caisse juste');
    }

    public function test_sans_billetage_le_montant_saisi_reste_valable(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->post('/caisse/cloture', ['especes_comptees' => '0'])->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->first();
        $this->assertNull($c->billetage);
        $this->get("/clotures/{$c->id}")->assertOk()->assertDontSee('× 20 000');

        $this->post('/caisse/cloture', ['especes_comptees' => '0', 'billetage' => ['20000' => -3]])->assertSessionHasErrors('billetage.20000');
    }

    public function test_achat_paye_par_carte_cadeau_hors_du_total_encaisse(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $this->actingAs($admin)->post('/cartes-cadeaux', ['montant' => '300 000', 'mode' => 'especes']);
        $carte = $this->dans($b, fn () => CarteCadeau::first());
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $carte->code])->assertRedirect();

        $this->post('/caisse/cloture', ['especes_comptees' => '300 000'])->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->first();
        // L'argent est entré une fois (vente de la carte) ; l'achat payé par carte n'est pas un second encaissement
        $this->assertSame(300_000, $c->encaissements['especes']);
        $this->assertSame(300_000, $c->encaissements['carte_cadeau']);
        $this->assertSame(300_000, $c->totalEncaisse());
        $this->assertSame(0, $c->ecart);
        $this->get("/clotures/{$c->id}")->assertSee('(hors total)');
    }
}
