<?php

namespace Tests\Feature;

use App\Models\Devis;
use App\Models\Produit;
use App\Models\Vente;
use App\Services\VenteService;
use Tests\TestCase;

/** Prix de vente TVA comprise : le client paie le prix affiché, la TVA en est extraite. */
class PrixTtcTest extends TestCase
{
    /** Boutique assujettie à 18 %, prix TTC ; un produit affiché 118 000 (achat 50 000). */
    private function boutiqueTtc(): array
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18, 'prix_ttc' => true]);
        $p = $this->produit($b->fresh(), ['prix_achat' => 50_000, 'prix_vente' => 118_000], 20);

        return [$b->fresh(), $admin, $p];
    }

    private function vendre($b, $admin, array $donnees): Vente
    {
        return $this->dans($b, fn () => app(VenteService::class)->creer($donnees + ['mode' => 'especes'], true), $admin);
    }

    public function test_le_client_paie_le_prix_affiche_et_la_tva_en_est_extraite(): void
    {
        [$b, $admin, $p] = $this->boutiqueTtc();
        $v = $this->vendre($b, $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);

        $this->assertTrue($v->prix_ttc);
        $this->assertSame([118_000, 18_000, 100_000], [$v->total_ttc, $v->total_tva, $v->total_ht]);
        $this->assertSame(118_000, $v->montant_paye);
        $this->assertSame(118_000, $v->sousTotal());

        // Documents : prix affiché, TVA « comprise »
        $this->actingAs($admin)->get("/ventes/{$v->id}")->assertOk()->assertSee('dont TVA 18 %')->assertSee('118 000');
        $this->get("/ventes/{$v->id}/recu")->assertOk()->assertSee('dont TVA 18 %')->assertSee('118 000');
        $this->get("/ventes/{$v->id}/facture")->assertOk();
        $this->get('/caisse')->assertSee('dont TVA')->assertSee('const prixTtc = true', false);
    }

    public function test_remise_et_plusieurs_taux(): void
    {
        [$b, $admin, $p] = $this->boutiqueTtc();
        $riz = $this->produit($b, ['designation' => 'Riz exonéré', 'prix_achat' => 5_000, 'prix_vente' => 10_000, 'taux_tva' => 0], 20);

        // Remise de 18 000 : le client paie 100 000, TVA extraite de la part taxée après remise
        $v = $this->vendre($b, $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'remise' => 18_000]);
        $this->assertSame(100_000, $v->total_ttc);
        $this->assertSame((int) round(100_000 * 18 / 118), $v->total_tva);
        $this->assertSame($v->total_ttc, $v->total_ht - $v->remise + $v->total_tva, 'total_ttc = total_ht − remise + tva');

        // Exonéré + 18 % : 128 000 payés, TVA seulement sur le produit taxé
        $v2 = $this->vendre($b, $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1], ['produit_id' => $riz->id, 'quantite' => 1]]]);
        $this->assertSame([128_000, 18_000], [$v2->total_ttc, $v2->total_tva]);
        $ventilation = collect($v2->ventilationTva())->keyBy('taux');
        $this->assertSame(100_000, $ventilation[18.0]['base']);   // base hors taxe
        $this->assertSame(10_000, $ventilation[0.0]['base']);
    }

    public function test_retour_rend_le_prix_paye(): void
    {
        [$b, $admin, $p] = $this->boutiqueTtc();
        $v = $this->vendre($b, $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 2]]]);
        $this->assertSame(236_000, $v->total_ttc);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes');
        $v->refresh();
        $this->assertSame([118_000, 18_000, 100_000, 118_000], [$v->total_ttc, $v->total_tva, $v->total_ht, $v->montant_paye]);
        $this->assertSame(118_000, (int) $this->dans($b, fn () => \App\Models\Retour::first())->rembourse);
    }

    public function test_marges_hors_taxe_et_vente_a_perte(): void
    {
        [$b, $admin, $p] = $this->boutiqueTtc();
        $this->vendre($b, $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 2]]]);

        // Marge = 2 × (100 000 HT − 50 000) = 100 000, pas 136 000 (la TVA n'est pas un gain)
        $this->actingAs($admin)->get('/rapports/produits-vendus/ecran')->assertOk()->assertSee('200 000')->assertSee('100 000')->assertDontSee('136 000');
        $v = $this->dans($b, fn () => Vente::first());
        $this->assertSame(100_000, $this->dans($b, fn () => $v->fresh('lignes')->marge()));

        // 55 000 TTC = 46 610 HT, sous le prix d'achat de 50 000 : refusé
        $b->update(['vente_a_perte' => false]);
        $this->expectException(\App\Exceptions\OperationRefusee::class);
        $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1, 'prix_unitaire' => 55_000]]]);
    }

    public function test_devis_en_ttc_et_anciennes_ventes_inchangees(): void
    {
        [$b, $admin, $p] = $this->boutiqueTtc();
        // Une vente faite avant le passage en TTC reste en hors taxe
        $b->update(['prix_ttc' => false]);
        $ancienne = $this->vendre($b->fresh(), $admin, ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);
        $this->assertFalse($ancienne->prix_ttc);
        $this->assertSame(139_240, $ancienne->total_ttc);   // 118 000 HT + 18 %
        $b->update(['prix_ttc' => true]);
        $this->assertSame(139_240, $ancienne->fresh()->total_ttc);
        $this->actingAs($admin)->get("/ventes/{$ancienne->id}")->assertSee('TVA 18 %')->assertDontSee('dont TVA');

        // Devis au prix affiché, transformé en vente au même prix
        $this->post('/devis', ['client_nom' => 'Chantier', 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]])->assertSessionHasNoErrors();
        $d = $this->dans($b, fn () => Devis::latest('id')->first());
        $this->assertSame([118_000, 18_000, true], [$d->total_ttc, $d->total_tva, $d->prix_ttc]);
        $this->get("/devis/{$d->id}")->assertSee('dont TVA 18 %');
        $this->post("/devis/{$d->id}/vente", ['mode' => 'especes'])->assertRedirect();
        $this->assertSame(118_000, $this->dans($b, fn () => Vente::latest('id')->first())->total_ttc);
    }

    public function test_passage_en_ttc_ajuste_les_prix_pour_que_le_client_paie_pareil(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $b = $b->fresh();
        $ciment = $this->produit($b, ['designation' => 'Ciment', 'prix_achat' => 79_000, 'prix_vente' => 90_000, 'prix_gros' => 85_000], 20);
        $riz = $this->produit($b, ['designation' => 'Riz', 'prix_achat' => 5_000, 'prix_vente' => 10_000, 'taux_tva' => 0], 20);
        $this->dans($b, fn () => \App\Models\Promotion::create(['nom' => 'Fête', 'type' => 'prix', 'valeur' => 80_000, 'produit_id' => $ciment->id,
            'debut' => now()->toDateString(), 'fin' => now()->addWeek()->toDateString(), 'actif' => true]));
        $avant = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $ciment->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $champs = ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1F6F54', 'tva_active' => 1];

        $this->actingAs($admin)->put('/parametres', $champs + ['prix_ttc' => 1, 'ajuster_prix' => 1])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, '1 prix de vente ajustés'));
        $this->assertSame([106_200, 100_300], [$ciment->fresh()->prix_vente, $ciment->fresh()->prix_gros]);
        $this->assertSame(10_000, $riz->fresh()->prix_vente, 'produit exonéré inchangé');
        $this->assertSame(94_400.0, $this->dans($b, fn () => \App\Models\Promotion::first())->valeur);

        // Le client paie exactement ce qu'il payait avant (promotion comprise)
        $apres = $this->dans($b->fresh(), fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $ciment->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->assertSame($avant->total_ttc, $apres->total_ttc);

        // Retour au hors taxe : les prix redeviennent ceux d'origine
        $this->put('/parametres', $champs + ['ajuster_prix' => 1])->assertSessionHas('succes');
        $this->assertSame([90_000, 85_000], [$ciment->fresh()->prix_vente, $ciment->fresh()->prix_gros]);

        // Sans ajustement : les prix ne bougent pas, le commerçant est prévenu
        $this->put('/parametres', $champs + ['prix_ttc' => 1])->assertSessionHas('succes', fn ($m) => str_contains($m, "n'ont pas été modifiés"));
        $this->assertSame(90_000, $ciment->fresh()->prix_vente);
    }

    public function test_reglage_dans_les_parametres(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $this->actingAs($admin)->get('/parametres')->assertSee('Mes prix de vente sont')->assertSee('TVA comprise (TTC)', false);

        // Sans TVA activée, le réglage TTC n'a aucun effet
        $b->update(['tva_active' => false, 'prix_ttc' => true]);
        $this->assertFalse(VenteService::prixTtc($b->fresh()));
    }
}
