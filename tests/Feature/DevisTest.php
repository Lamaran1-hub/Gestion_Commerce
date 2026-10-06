<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Devis;
use App\Models\Produit;
use App\Models\Vente;
use Tests\TestCase;

class DevisTest extends TestCase
{
    private function creerDevis($admin, Produit $p, array $extra = [])
    {
        return $this->actingAs($admin)->post('/devis', ['lignes' => [['produit_id' => $p->id, 'quantite' => 12]]] + $extra);
    }

    public function test_devis_sans_mouvement_de_stock_avec_les_prix_de_gros(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, ['prix_gros' => 280_000, 'quantite_gros' => 10], 50);

        $this->creerDevis($admin, $p)->assertRedirect()->assertSessionHas('succes');
        $d = $this->dans($b, fn () => Devis::first());
        $this->assertSame(3_360_000, $d->total_ttc, '12 × 280 000 (prix de gros)');
        $this->assertSame(now()->addDays(15)->toDateString(), $d->valable_jusqu_au->toDateString());
        $this->assertEquals(50, $p->fresh()->stock, 'un devis ne sort rien du stock');

        $this->get("/devis/{$d->id}")->assertOk()->assertSee($d->numero)->assertSee('Transformer en vente');
        $this->get("/devis/{$d->id}/proforma")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/devis')->assertOk()->assertSee($d->numero);
    }

    public function test_transformation_en_vente_aux_prix_garantis(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 50); // 300 000
        $this->creerDevis($admin, $p);
        $d = $this->dans($b, fn () => Devis::first());

        // Le prix de vente augmente entre-temps : le devis garantit l'ancien prix
        $p->update(['prix_vente' => 350_000]);
        $this->post("/devis/{$d->id}/vente", ['mode' => 'orange_money'])->assertRedirect();

        $v = $this->dans($b, fn () => Vente::first());
        $this->assertSame(3_600_000, $v->total_ttc);
        $this->assertSame(300_000, $v->lignes->first()->prix_unitaire);
        $this->assertEquals(38, $p->fresh()->stock);
        $this->assertSame('converti', $d->fresh()->statut);

        // Une seule transformation
        $this->post("/devis/{$d->id}/vente", ['mode' => 'especes'])->assertSessionHas('erreur');
        $this->assertSame(1, $this->dans($b, fn () => Vente::count()));
    }

    public function test_devis_expire_non_transformable_mais_recreable_au_prix_du_jour(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 50);
        $this->creerDevis($admin, $p);
        $d = $this->dans($b, fn () => Devis::first());
        $p->update(['prix_vente' => 350_000]);

        $this->travel(16)->days();
        session()->put('derniere_activite', now()->timestamp);
        $this->assertTrue($d->fresh()->estExpire());
        $this->post("/devis/{$d->id}/vente", ['mode' => 'especes'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'expiré'));

        $this->post("/devis/{$d->id}/renouveler")->assertRedirect();
        $nouveau = $this->dans($b, fn () => Devis::latest('id')->first());
        $this->assertNotSame($d->id, $nouveau->id);
        $this->assertSame(4_200_000, $nouveau->total_ttc, '12 × 350 000 : prix du jour');
        $this->assertSame('annule', $d->fresh()->statut);
    }

    public function test_stock_insuffisant_a_la_transformation(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $this->creerDevis($admin, $p);
        $d = $this->dans($b, fn () => Devis::first());

        $this->dans($b, fn () => app(\App\Services\StockService::class)->ajuster($p->fresh(), 5, 'Casse'));
        $this->post("/devis/{$d->id}/vente", ['mode' => 'especes'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Stock insuffisant'));
        $this->assertSame('en_cours', $d->fresh()->statut);
    }

    public function test_devis_pour_client_grossiste_et_remise_plafonnee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['remise_max_pct' => 5]);
        $p = $this->produit($b, ['prix_gros' => 280_000], 50);
        $revendeur = $this->dans($b, fn () => Client::create(['nom' => 'Kaba', 'grossiste' => true]));

        $this->creerDevis($admin, $p, ['client_id' => $revendeur->id, 'remise' => '500 000'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'remise dépasse'));
        $this->creerDevis($admin, $p, ['client_id' => $revendeur->id])->assertSessionHas('succes');
        $this->assertSame(280_000, $this->dans($b, fn () => Devis::first()->lignes->first()->prix_unitaire));
    }
}
