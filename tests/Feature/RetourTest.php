<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Retour;
use App\Models\Role;
use App\Models\User;
use App\Services\CaisseService;
use App\Services\VenteService;
use Tests\TestCase;

/** Retours partiels de marchandise (avoirs). */
class RetourTest extends TestCase
{
    public function test_retour_partiel_paye_comptant_rembourse_et_remet_en_stock(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10); // 300 000 l'unité, achat 250 000
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 3]], 'mode' => 'especes']), $admin);
        $ligne = $v->lignes->first();

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 1], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Remboursez 300 000 GNF'));

        $v->refresh();
        $this->assertSame(600_000, $v->total_ttc);
        $this->assertSame(300_000, $v->montant_retourne);
        $this->assertSame(900_000, $v->totalInitial());
        $this->assertSame(600_000, $v->montant_paye);
        $this->assertEquals(2, $ligne->fresh()->quantite);
        $this->assertEquals(1, $ligne->fresh()->quantite_retournee);
        $this->assertEquals(8, $p->fresh()->stock, '10 − 3 vendus + 1 retourné');

        // La caisse du jour tient compte du remboursement
        $bilan = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
        $this->assertSame(600_000, $bilan['especes_theoriques']);

        $this->get("/ventes/{$v->id}")->assertSee('RET-')->assertSee('1 retourné(s)');
        $this->get("/ventes/{$v->id}/facture")->assertOk();
    }

    public function test_retour_sur_une_vente_a_credit_diminue_la_dette_sans_remboursement(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Diallo']));
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $client->id,
            'lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'montant_recu' => 0]), $admin);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'motif_autre' => 'Trop commandé', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes');
        $this->assertSame(300_000, $this->dans($b, fn () => $client->fresh()->soldeDu()));
        $r = Retour::withoutGlobalScope('boutique')->first();
        $this->assertSame(0, $r->rembourse);
        $this->assertSame('Trop commandé', $r->motif);
    }

    public function test_remise_et_tva_reparties_au_prorata(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $p = $this->produit($b, [], 10);
        // 2 × 300 000 = 600 000 ; remise 60 000 ; TVA 18 % de 540 000 = 97 200 ; total 637 200
        $v = $this->dans($b->fresh(), fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'remise' => 60_000, 'mode' => 'especes'], true), $admin);
        $this->assertSame(637_200, $v->total_ttc);

        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Erreur de produit', 'mode_remboursement' => 'orange_money']);
        $v->refresh();
        $this->assertSame(318_600, $v->total_ttc, 'moitié exacte : remise et TVA au prorata');
        $this->assertSame(30_000, $v->remise);
        $this->assertSame(48_600, $v->total_tva);
    }

    public function test_regles_quantite_delai_et_caisse(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $ligne = $v->lignes->first();

        // Pas plus que ce qui a été vendu
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 3], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'seulement 2'));
        // Au moins une quantité
        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 0], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])->assertSessionHas('erreur');

        // Au-delà du délai : réservé à l'administrateur
        $b->update(['delai_retour_jours' => 7]);
        $gestionnaire = User::create(['boutique_id' => $b->id, 'prenom' => 'G', 'nom' => 'G', 'email' => 'g@test.gn', 'password' => 'secret123',
            'role_id' => Role::withoutGlobalScope('boutique')->where('boutique_id', $b->id)->where('nom', 'Gestionnaire')->value('id')]);
        $this->travel(10)->days();
        session()->put('derniere_activite', now()->timestamp); // nouvelle session (sinon : déconnexion pour inactivité)
        $this->actingAs($gestionnaire)->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'plus de 7 jours'));
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes');
        $this->travelBack();
        session()->put('derniere_activite', now()->timestamp);

        // Remboursement impossible depuis une caisse clôturée
        $this->actingAs($admin)->post('/caisse/cloture', ['especes_comptees' => '0', 'motif' => 'Autre']);
        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'caisse est clôturée'));
    }

    public function test_marge_du_tableau_de_bord_nette_des_retours(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes']);

        // Marge nette : 1 × (300 000 − 250 000)
        $this->get('/tableau-de-bord?periode=jour')->assertOk()->assertSee('50 000 GNF');
    }
}
