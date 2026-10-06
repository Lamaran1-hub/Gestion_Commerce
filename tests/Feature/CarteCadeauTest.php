<?php

namespace Tests\Feature;

use App\Models\CarteCadeau;
use App\Models\Paiement;
use App\Models\Vente;
use App\Services\CaisseService;
use App\Services\CartesCadeaux;
use App\Services\Tresorerie;
use Tests\TestCase;

/** Cartes cadeaux : vendues aujourd'hui (argent en caisse), dépensées plus tard avec leur code. */
class CarteCadeauTest extends TestCase
{
    private function vendreCarte($b, $admin, array $extra = []): CarteCadeau
    {
        $this->actingAs($admin)->post('/cartes-cadeaux', $extra + ['montant' => '500 000', 'mode' => 'especes', 'beneficiaire' => 'Fatoumata', 'telephone' => '620112233'])
            ->assertSessionHasNoErrors()->assertSessionHas('imprimer_carte');

        return $this->dans($b, fn () => CarteCadeau::latest('id')->first());
    }

    private function bilan($b, $admin): array
    {
        return $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
    }

    public function test_carte_vendue_l_argent_entre_en_caisse_sans_chiffre_d_affaires(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $c = $this->vendreCarte($b, $admin, ['acheteur' => 'Mamadou']);

        $this->assertSame([500_000, 500_000, 'active'], [$c->montant, $c->solde, $c->etat()]);
        $this->assertMatchesRegularExpression('/^[2-9A-HJKMNP-Z]{8}$/', $c->code);
        $this->assertNull($c->expire_le, 'date laissée vide : sans limite');
        $this->get('/cartes-cadeaux')->assertSee('value="'.now()->addYear()->toDateString().'"', false);   // un an proposé par défaut
        $bilan = $this->bilan($b, $admin);
        $this->assertSame(500_000, $bilan['especes_theoriques'], 'l\'argent est dans le tiroir');
        $this->assertSame(0, $bilan['total_ventes'], 'pas encore de chiffre d\'affaires');
        $this->assertSame(0, $this->dans($b, fn () => Vente::count()));

        $lignes = $this->dans($b, fn () => app(Tresorerie::class)->lignes('caisse', now()->subDay(), now()->addMinute()));
        $this->assertSame(500_000, (int) $lignes->sum('entree'));

        // Pages : liste, fiche (code masqué), carte imprimable (code complet, WhatsApp)
        $this->get('/cartes-cadeaux')->assertOk()->assertSee($c->codeMasque())->assertSee('Fatoumata')->assertDontSee($c->codeLisible());
        $this->get("/cartes-cadeaux/{$c->id}")->assertOk()->assertSee('Carte vendue')->assertSee('500 000 GNF')->assertDontSee($c->codeLisible());
        $this->get("/cartes-cadeaux/{$c->id}/imprimer")->assertOk()->assertSee('CARTE CADEAU')->assertSee($c->codeLisible())
            ->assertSee('De la part de : Mamadou')->assertSee('wa.me/224620112233', false);
        $this->get('/cartes-cadeaux?q='.substr($c->code, -4))->assertSee($c->codeMasque());
    }

    public function test_paiement_en_plusieurs_fois_puis_carte_epuisee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);   // 300 000 l'unité
        $c = $this->vendreCarte($b, $admin);

        // Vérification depuis la caisse : code saisi avec tirets et en minuscules
        $this->getJson('/caisse/carte-cadeau?code='.strtolower($c->codeLisible()))
            ->assertJson(['ok' => true, 'solde' => 500_000, 'code' => $c->codeLisible()]);
        $this->getJson('/caisse/carte-cadeau?code=CC-AAAA-AAAA')->assertJson(['ok' => false, 'message' => 'Carte cadeau introuvable : vérifiez le code.']);

        // 1er achat : 300 000 entièrement payés par la carte
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $c->code])->assertRedirect();
        $this->assertSame(200_000, $c->fresh()->solde);
        $v1 = $this->dans($b, fn () => Vente::with('paiements')->latest('id')->first());
        $this->assertEquals(['carte_cadeau' => 300_000], $v1->paiements->pluck('montant', 'mode')->all());
        $this->assertSame('Carte '.$c->codeMasque(), $v1->paiements->first()->reference);

        // 2e achat : 600 000, la carte paie 200 000, le reste en Orange Money
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'orange_money', 'carte_cadeau' => $c->codeLisible()])->assertRedirect();
        $v2 = $this->dans($b, fn () => Vente::with('paiements')->latest('id')->first());
        $this->assertEquals(['carte_cadeau' => 200_000, 'orange_money' => 400_000], $v2->paiements->pluck('montant', 'mode')->all());
        $this->assertSame(600_000, $v2->montant_paye);
        $this->assertSame([0, 'epuisee'], [$c->fresh()->solde, $c->fresh()->etat()]);

        // Plus rien à dépenser
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $c->code])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà été entièrement utilisée'));

        // Tiroir : 500 000 de la carte, rien de plus pour les achats payés par carte
        $bilan = $this->bilan($b, $admin);
        $this->assertSame(500_000, $bilan['especes_theoriques']);
        $this->assertSame(900_000, $bilan['total_ventes']);
        // Trésorerie : la carte n'est comptée sur aucun compte au moment où elle est dépensée
        $this->assertSame(500_000, (int) $this->dans($b, fn () => app(Tresorerie::class)->lignes('caisse', now()->subDay(), now()->addMinute()))->sum('entree'));
        $this->assertSame(0, (int) $this->dans($b, fn () => app(Tresorerie::class)->lignes('autre', now()->subDay(), now()->addMinute()))->sum('entree'));
    }

    public function test_vente_annulee_et_retour_recreditent_la_carte(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $c = $this->vendreCarte($b, $admin);

        // Annulation : les 300 000 retournent sur la carte
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $c->code]);
        $v = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])->assertSessionHasNoErrors();
        $this->assertSame(500_000, $c->fresh()->solde);

        // Retour d'un article sur 2 : 300 000 payés par carte (500 000 carte + 100 000 espèces) → recrédités sur la carte
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes', 'carte_cadeau' => $c->code]);
        $v = $this->dans($b, fn () => Vente::with('lignes')->latest('id')->first());
        $this->assertSame(0, $c->fresh()->solde);
        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHasNoErrors();
        $this->assertSame(300_000, $c->fresh()->solde, 'ce qui a été payé par carte retourne sur la carte, pas en espèces');
        $this->assertSame(300_000, $v->fresh()->montant_paye);
        $this->assertSame(-300_000, (int) $this->dans($b, fn () => Paiement::where('vente_id', $v->id)->where('montant', '<', 0)->sum('montant')));
        $this->assertSame(0, (int) $this->dans($b, fn () => Paiement::where('vente_id', $v->id)->where('mode', 'especes')->where('montant', '<', 0)->count()));
        $this->get("/cartes-cadeaux/{$c->id}")->assertSee('Recréditée');
    }

    public function test_carte_expiree_refusee_puis_prolongee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $c = $this->vendreCarte($b, $admin);
        $this->dans($b, fn () => $c->update(['expire_le' => now()->subDay()]));

        $this->getJson('/caisse/carte-cadeau?code='.$c->code)->assertJson(['ok' => false]);
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $c->code])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'a expiré'));
        $this->assertSame(0, $this->dans($b, fn () => Vente::count()));
        $this->get('/cartes-cadeaux')->assertSee('Expirée');

        $this->post("/cartes-cadeaux/{$c->id}/prolonger", ['expire_le' => now()->addMonths(3)->toDateString()])->assertSessionHas('succes');
        $this->getJson('/caisse/carte-cadeau?code='.$c->code)->assertJson(['ok' => true]);
    }

    public function test_annulation_rend_le_solde_et_bloque_la_carte(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $c = $this->vendreCarte($b, $admin);
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $c->code]);

        $this->post("/cartes-cadeaux/{$c->id}/annuler", ['mode_remboursement' => 'especes'])->assertSessionHasErrors('motif');
        $this->post("/cartes-cadeaux/{$c->id}/annuler", ['mode_remboursement' => 'especes', 'motif' => 'Client remboursé'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Rendez 200 000 GNF'));
        $c->refresh();
        $this->assertSame(['annulee', 0], [$c->statut, $c->solde]);
        // 500 000 reçus − 200 000 rendus
        $this->assertSame(300_000, $this->bilan($b, $admin)['especes_theoriques']);
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'carte_cadeau' => $c->code])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'annulée'));
        $this->get("/cartes-cadeaux/{$c->id}/imprimer")->assertNotFound();
    }

    public function test_regles_vente_droits_isolation_et_compta(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->post('/cartes-cadeaux', ['montant' => '500', 'mode' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'au moins 1 000 GNF'));
        $c = $this->vendreCarte($b, $admin, ['mode' => 'orange_money', 'reference' => 'OM777']);

        // Export comptable équilibré : trésorerie mobile money ↔ avances reçues
        $compta = $this->dans($b, fn () => (fn () => $this->comptable(now()->startOfDay(), now()->endOfDay()))->call(app(\App\Http\Controllers\RapportController::class)), $admin);
        $lignes = collect($compta[1])->where('piece', 'CC'.$c->id);
        $this->assertEquals(['552' => 500_000, '4191' => 0], $lignes->pluck('debit', 'compte')->all());
        $this->assertSame($compta[3]['debit'], $compta[3]['credit']);

        // Un vendeur ne peut pas annuler une carte
        $vendeur = $this->dans($b, function () use ($b) {
            $role = \App\Models\Role::where('nom', 'Vendeur')->first();

            return \App\Models\User::create(['boutique_id' => $b->id, 'prenom' => 'Kadi', 'nom' => 'Sow', 'email' => 'vendeur@test.gn', 'password' => 'secret123', 'role_id' => $role->id, 'actif' => true]);
        });
        $this->actingAs($vendeur)->post("/cartes-cadeaux/{$c->id}/annuler", ['mode_remboursement' => 'especes', 'motif' => 'x'])->assertForbidden();
        $this->actingAs($vendeur)->get('/cartes-cadeaux')->assertOk();

        // Une autre boutique ne trouve pas la carte, même avec le code
        [, $autre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->actingAs($autre->fresh())->get("/cartes-cadeaux/{$c->id}")->assertNotFound();
        $this->getJson('/caisse/carte-cadeau?code='.$c->code)->assertJson(['ok' => false]);

        // Code saisi en minuscules, avec préfixe, tirets ou espaces
        $this->assertSame($c->code, CartesCadeaux::normaliser(' cc-'.strtolower(substr($c->code, 0, 4)).' '.substr($c->code, 4)));
    }
}
