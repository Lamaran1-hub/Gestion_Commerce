<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Retour;
use App\Models\Vente;
use App\Services\CaisseService;
use App\Services\Registre;
use App\Services\VenteService;
use Tests\TestCase;

/** Échange d'articles : retour converti en bon d'échange, utilisé aussitôt en caisse ; seule la différence est payée ou rendue. */
class EchangeTest extends TestCase
{
    /** Vente comptoir de 2 sacs à 300 000 payée en espèces, puis retour d'un sac en échange. */
    private function echangeComptoir(): array
    {
        [$b, $admin] = $this->creerBoutique();
        $riz = $this->produit($b, [], 10);   // 300 000 l'unité
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $riz->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Erreur de produit', 'mode_remboursement' => 'echange'])
            ->assertRedirect(route('ventes.create', ['echange' => Retour::withoutGlobalScopes()->sole()->id]))
            ->assertSessionHas('succes', fn ($m) => str_contains($m, "Bon d'échange de 300 000 GNF"));

        return [$b, $admin, $riz, $v, Retour::withoutGlobalScopes()->sole()];
    }

    private function especes($b, $admin): int
    {
        return $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()))['especes_theoriques'];
    }

    public function test_echange_contre_moins_cher_rend_la_difference_en_especes(): void
    {
        [$b, $admin, $riz, $v, $bon] = $this->echangeComptoir();
        $this->assertSame([300_000, 'echange', 300_000], [$bon->rembourse, $bon->mode_remboursement, $bon->echange_restant]);
        $this->assertEquals(9, $riz->fresh()->stock);
        $this->assertSame(600_000, $this->especes($b, $admin), 'rien n\'est sorti de la caisse au retour');

        // La caisse affiche le bon
        $this->get("/caisse?echange={$bon->id}")->assertOk()->assertSee("Échange {$bon->numero}")->assertSee('300 000 GNF')
            ->assertSee('1 × Riz 25 kg')->assertSee('name="echange_id" value="'.$bon->id.'"', false);

        $huile = $this->produit($b, ['designation' => 'Huile 5 L', 'prix_achat' => 80_000, 'prix_vente' => 100_000], 5);
        $this->post('/ventes', ['lignes' => [['produit_id' => $huile->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 0, 'echange_id' => $bon->id])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, "Échange {$bon->numero} effectué") && str_contains($m, 'Rendez 200 000 GNF au client'));

        $nouvelle = Vente::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame([100_000, 0], [$nouvelle->montant_paye, $nouvelle->resteAPayer()]);
        $this->assertSame([0, $nouvelle->id], [$bon->fresh()->echange_restant, $bon->fresh()->echange_vente_id]);
        $this->assertSame(300_000, $v->fresh()->montant_paye, 'la vente d\'origine reste équilibrée');
        $this->assertSame(400_000, $this->especes($b, $admin), '200 000 rendus au client, le bon ne compte pas comme de l\'argent');
        $this->assertEquals(4, $huile->fresh()->stock);

        // Le bon ne sert qu'une fois
        $this->post('/ventes', ['lignes' => [['produit_id' => $huile->id, 'quantite' => 1]], 'mode' => 'especes', 'echange_id' => $bon->id])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà été utilisé'));
        $this->get("/caisse?echange={$bon->id}")->assertDontSee("Échange {$bon->numero}");

        // Traçabilité : la vente d'origine renvoie vers l'échange, le registre reste intact
        $this->get("/ventes/{$v->id}")->assertSee('échangé : bon de 300 000')->assertSee("utilisé sur la vente {$nouvelle->numero}");
        $verif = $this->dans($b, fn () => app(Registre::class)->verifier($b));
        $this->assertTrue($verif['intact'], implode(' | ', $verif['anomalies'] ?? []));
    }

    public function test_echange_contre_plus_cher_le_client_paie_la_difference(): void
    {
        [$b, $admin, , , $bon] = $this->echangeComptoir();
        $tele = $this->produit($b, ['designation' => 'Téléviseur', 'prix_achat' => 400_000, 'prix_vente' => 500_000], 2);

        $this->post('/ventes', ['lignes' => [['produit_id' => $tele->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 200_000, 'echange_id' => $bon->id])
            ->assertSessionHas('succes', fn ($m) => ! str_contains($m, 'Rendez'));
        $nouvelle = Vente::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0, $nouvelle->resteAPayer());
        $this->assertSame(['echange' => 300_000, 'especes' => 200_000], $nouvelle->paiements->pluck('montant', 'mode')->map(fn ($m) => (int) $m)->all());
        $this->assertSame(800_000, $this->especes($b, $admin));

        // Annuler la nouvelle vente redonne sa valeur au bon ; la vente d'origine ne peut plus être annulée (double remboursement)
        $this->dans($b, fn () => app(VenteService::class)->annuler($nouvelle, 'Le client a changé d\'avis'), $admin);
        $this->assertSame([300_000, null], [$bon->fresh()->echange_restant, $bon->fresh()->echange_vente_id]);
        $this->get("/ventes/{$bon->vente_id}")->assertSee('Utiliser le bon en caisse');
        $this->expectException(\App\Exceptions\OperationRefusee::class);
        $this->dans($b, fn () => app(VenteService::class)->annuler(Vente::withoutGlobalScopes()->find($bon->vente_id), 'Test'), $admin);
    }

    public function test_echange_d_un_client_connu_et_bon_d_une_autre_boutique(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $riz = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Camara', 'telephone' => '621000111']));
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $client->id, 'lignes' => [['produit_id' => $riz->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'echange']);
        $bon = Retour::withoutGlobalScopes()->sole();
        // Le client est présélectionné à la caisse
        $this->get("/caisse?echange={$bon->id}&client={$client->id}")->assertOk()->assertSee('value="'.$client->id.'"', false)
            ->assertSee('selected>'.$client->nomComplet(), false);

        // Le bon d'une boutique n'est jamais utilisable dans une autre
        [$autre, $adminAutre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $p = $this->produit($autre, [], 5);
        $this->actingAs($adminAutre->fresh())->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 0, 'echange_id' => $bon->id])
            ->assertSessionHas('erreur');
        $this->assertSame(300_000, $bon->fresh()->echange_restant);
    }
}
