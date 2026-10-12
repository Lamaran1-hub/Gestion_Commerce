<?php

namespace Tests\Feature;

use App\Models\MouvementStock;
use App\Models\Produit;
use App\Services\StockService;
use Tests\TestCase;

/** Inventaire tournant : quelques produits à compter par jour, priorité aux jamais comptés et aux plus chers. */
class InventaireTournantTest extends TestCase
{
    public function test_selection_du_jour_et_couverture(): void
    {
        config(['gestion.comptage_par_jour' => 2]);
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(9, 0));
        [$b, $admin] = $this->creerBoutique();
        $savon = $this->produit($b, ['designation' => 'Savon', 'prix_achat' => 1_000, 'prix_vente' => 1_500], 20);        // 20 000 immobilisés
        $huile = $this->produit($b, ['designation' => 'Huile 5 L', 'prix_achat' => 60_000, 'prix_vente' => 75_000], 10);   // 600 000
        $riz = $this->produit($b, ['designation' => 'Riz 25 kg'], 4);                                                         // 1 000 000
        $this->dans($b, fn () => Produit::create(['designation' => 'Ancien', 'unite' => 'pièce', 'prix_achat' => 1, 'prix_vente' => 2, 'actif' => false]));

        // Jamais comptés : les plus chers d'abord
        $this->actingAs($admin)->get('/tableau-de-bord')->assertSee('Comptage du jour :')->assertSee('2 produit(s) à compter');
        $this->get('/stock/inventaire?tournant=1')->assertOk()->assertSeeInOrder(['Riz 25 kg', 'Huile 5 L'])->assertDontSee('Savon')
            ->assertSee('0 % du stock vérifié')->assertSee('jamais compté');

        // Comptage : le riz était juste, l'huile a un manquant
        $this->post('/stock/inventaire', ['tournant' => 1, 'comptes' => [$riz->id => 4, $huile->id => 9], 'theoriques' => [$riz->id => 4, $huile->id => 10]])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, '2 produit(s) compté(s), 1 ajusté(s)'));
        $this->assertEquals(9, $huile->fresh()->stock);
        $this->assertNotNull($riz->fresh()->compte_le, 'compté même sans écart');
        $this->assertSame('Comptage du jour du 10/11/2026', $this->dans($b, fn () => MouvementStock::where('type', 'ajustement')->sole())->motif);

        // Le quota du jour est atteint : plus de rappel, liste vide
        $this->get('/tableau-de-bord')->assertDontSee('Comptage du jour :');
        $this->get('/stock/inventaire?tournant=1')->assertSee('Comptage du jour terminé')->assertSee('66 % du stock vérifié');

        // Le lendemain : le savon (jamais compté) passe en premier
        $this->travelTo(now()->addDay());
        $this->flushSession();
        $this->actingAs($admin->fresh())->get('/stock/inventaire?tournant=1')->assertSee('Savon')->assertDontSee('Huile 5 L');

        // Un comptage de plus de 30 jours expire : le produit revient dans la liste
        Produit::withoutGlobalScopes()->whereIn('id', [$riz->id, $huile->id])->update(['compte_le' => now()->subDays(31)]);
        $this->dans($b, fn () => Produit::whereKey($savon->id)->update(['compte_le' => now()->subDay()]));
        $this->get('/stock/inventaire?tournant=1')->assertSeeInOrder(['Riz 25 kg', 'Huile 5 L'])->assertSee('33 % du stock vérifié');
    }

    public function test_une_vente_pendant_le_comptage_n_est_pas_effacee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $huile = $this->produit($b, ['designation' => 'Huile 5 L'], 10);
        // Page ouverte avec 10 en stock ; pendant le comptage la caisse vend 3 bidons
        $this->dans($b, fn () => app(StockService::class)->mouvement($huile, 'vente', -3));

        // Le rayon comptait 9 (1 manquant) avant la vente : le stock final doit être 6, pas 9
        $this->actingAs($admin)->post('/stock/inventaire', ['comptes' => [$huile->id => 9], 'theoriques' => [$huile->id => 10]])->assertSessionHas('succes');
        $this->assertEquals(6, $huile->fresh()->stock);

        // Jamais négatif, même si l'écart dépasse ce qui reste
        $this->post('/stock/inventaire', ['comptes' => [$huile->id => 0], 'theoriques' => [$huile->id => 50]]);
        $this->assertEquals(0, $huile->fresh()->stock);

        // Sans stock affiché (ancien formulaire), le comptage fixe le stock
        $this->post('/stock/inventaire', ['comptes' => [$huile->id => 5]]);
        $this->assertEquals(5, $huile->fresh()->stock);

        // Rien saisi : message d'erreur, rien ne change
        $this->post('/stock/inventaire', ['comptes' => [$huile->id => '']])->assertSessionHas('erreur');
    }
}
