<?php

namespace Tests\Feature;

use App\Models\Avoir;
use App\Models\Client;
use App\Models\Paiement;
use App\Services\CaisseService;
use App\Services\VenteService;
use Tests\TestCase;

/** Avoirs clients : retour rendu en bon d'achat, dépensé plus tard à la caisse. */
class AvoirTest extends TestCase
{
    private function venteAuClient($b, $admin, $client, $p, int $quantite = 2, array $extra = [])
    {
        return $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $client->id,
            'lignes' => [['produit_id' => $p->id, 'quantite' => $quantite]], 'mode' => 'especes'] + $extra), $admin);
    }

    public function test_retour_rendu_en_avoir_sans_sortir_d_argent(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);   // 300 000 l'unité
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Diallo', 'telephone' => '620112233']));
        $v = $this->venteAuClient($b, $admin, $client, $p);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'avoir'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, '300 000 GNF crédités en avoir'));

        $this->assertSame(300_000, $client->fresh()->avoir);
        $this->assertSame(300_000, $v->fresh()->montant_paye);
        $this->assertEquals(9, $p->fresh()->stock);
        // L'argent reste dans le tiroir
        $bilan = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
        $this->assertSame(600_000, $bilan['especes_theoriques']);
        $this->get("/ventes/{$v->id}")->assertSee('rendu en avoir');
        $this->get("/clients/{$client->id}")->assertSee('Avoir disponible')->assertSee('300 000');
    }

    public function test_bon_d_avoir_imprimable_apres_le_retour_et_depuis_la_fiche(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Diallo', 'prenom' => 'Aïssata', 'telephone' => '620112233']));
        $v = $this->venteAuClient($b, $admin, $client, $p);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'avoir'])
            ->assertSessionHas('bon_avoir');
        $retour = \App\Models\Retour::withoutGlobalScopes()->sole();
        $code = \App\Http\Controllers\AvoirController::code($client->fresh(), $retour->numero, 300_000);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}-[0-9A-F]{4}$/', $code);

        $this->get("/ventes/{$v->id}")->assertSee("Imprimer le bon d'avoir", false)->assertSee(route('retours.bon-avoir', $retour));
        $this->get("/retours/{$retour->id}/bon-avoir")->assertOk()
            ->assertSee("BON D'AVOIR", false)->assertSee('Aïssata Diallo')->assertSee($retour->numero)->assertSee('Avoir accordé')
            ->assertSee('300 000 GNF')->assertSee($code)->assertSee('Non remboursable en espèces')->assertSee('wa.me/224620112233', false);

        // Bon de solde depuis la fiche client, avec un code différent
        $this->get("/clients/{$client->id}")->assertSee('Bon de solde');
        $this->get("/clients/{$client->id}/bon-avoir")->assertOk()->assertSee("Solde d'avoir disponible", false)->assertSee('SOLDE-'.$client->code);

        // Une autre boutique ne voit pas ce bon
        [, $autre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->actingAs($autre->fresh())->get("/retours/{$retour->id}/bon-avoir")->assertNotFound();
    }

    public function test_pas_de_bon_sans_avoir(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Barry']));
        $v = $this->venteAuClient($b, $admin, $client, $p);
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionMissing('bon_avoir');
        $retour = \App\Models\Retour::withoutGlobalScopes()->sole();

        $this->get("/retours/{$retour->id}/bon-avoir")->assertNotFound();   // remboursé en espèces
        $this->get("/clients/{$client->id}/bon-avoir")->assertNotFound();     // solde nul
        $this->get("/clients/{$client->id}")->assertDontSee('Bon de solde');
    }

    public function test_avoir_nominatif_refuse_sans_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'avoir'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'nominatif'));
        $this->assertEquals(9, $p->fresh()->stock, 'Rien n\'a été repris');
    }

    public function test_avoir_depense_a_la_caisse_puis_rendu_si_la_vente_est_annulee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['fidelite_taux' => 5]);
        $p = $this->produit($b, [], 20);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Camara']));
        $v1 = $this->venteAuClient($b, $admin, $client, $p, 1);
        $this->actingAs($admin)->post("/ventes/{$v1->id}/retours", ['quantites' => [$v1->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'avoir']);
        $this->assertSame(300_000, $client->fresh()->avoir);
        $pointsAvant = $client->fresh()->points;

        // Nouvel achat de 600 000 : 300 000 d'avoir + 300 000 en espèces
        $this->get('/caisse')->assertSee('data-avoir="300000"', false);
        $this->post('/ventes', ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes',
            'utiliser_avoir' => 1, 'montant_recu' => '300 000'])->assertSessionHas('succes');
        $v2 = $client->ventes()->latest('id')->first();
        $this->assertSame(600_000, $v2->montant_paye);
        $this->assertSame(300_000, (int) Paiement::where('vente_id', $v2->id)->where('mode', 'avoir')->sum('montant'));
        $this->assertSame(0, $client->fresh()->avoir);
        $this->assertSame($pointsAvant + 15_000, $client->fresh()->points, 'Points gagnés sur les 300 000 payés en espèces seulement');
        $this->get("/ventes/{$v2->id}/recu")->assertSee('Avoir client');

        // Annulation : l'avoir dépensé est rendu au client
        $this->post("/ventes/{$v2->id}/annuler", ['motif' => 'Erreur de saisie'])->assertSessionHas('succes');
        $this->assertSame(300_000, $client->fresh()->avoir);
    }

    public function test_une_vente_reprise_en_avoir_ne_s_annule_plus(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Bah']));
        $v = $this->venteAuClient($b, $admin, $client, $p);
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'avoir']);

        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'repris en avoir'));
        $this->assertSame('validee', $v->fresh()->statut);
    }

    public function test_ce_qui_a_ete_paye_en_avoir_revient_en_avoir(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Sow']));
        $v1 = $this->venteAuClient($b, $admin, $client, $p, 1);
        $this->actingAs($admin)->post("/ventes/{$v1->id}/retours", ['quantites' => [$v1->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'avoir']);
        $v2 = $this->venteAuClient($b, $admin, $client, $p, 1, ['utiliser_avoir' => 1]);   // payée entièrement en avoir
        $this->assertSame(0, $client->fresh()->avoir);

        // Même si le vendeur choisit « espèces », on ne rend pas d'argent pour un achat payé en avoir
        $this->post("/ventes/{$v2->id}/retours", ['quantites' => [$v2->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'crédités en avoir'));
        $this->assertSame(300_000, $client->fresh()->avoir);
        $this->assertSame(0, (int) Paiement::where('vente_id', $v2->id)->where('mode', 'especes')->sum('montant'));
    }

    public function test_rapport_z_et_comptabilite_ne_comptent_pas_l_avoir_comme_de_l_argent(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Keita']));
        $v1 = $this->venteAuClient($b, $admin, $client, $p, 1);
        $this->actingAs($admin)->post("/ventes/{$v1->id}/retours", ['quantites' => [$v1->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'avoir']);
        $this->venteAuClient($b, $admin, $client, $p, 1, ['utiliser_avoir' => 1]);

        $this->post('/caisse/cloture', ['especes_comptees' => '300 000'])->assertSessionHasNoErrors();
        $cloture = \App\Models\ClotureCaisse::withoutGlobalScopes()->latest('id')->first();
        $this->assertSame(0, $cloture->ecart);
        $this->assertSame(300_000, $cloture->totalEncaisse(), 'Seules les espèces réellement reçues');
        $this->assertSame(2, Avoir::withoutGlobalScopes()->count());
    }
}
