<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\MouvementStock;
use App\Models\PaiementFournisseur;
use App\Models\Produit;
use App\Models\RetourFournisseur;
use App\Services\CaisseService;
use App\Services\Tresorerie;
use Tests\TestCase;

class RetourFournisseurTest extends TestCase
{
    /** Réception de 10 × 100 000 GNF (1 000 000) chez Sodeci, avec le règlement demandé. */
    private function reception($b, $admin, array $reglement): array
    {
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci']));
        $p = $this->produit($b, [], 0);
        $this->actingAs($admin)->post('/approvisionnements', [
            'fournisseur_id' => $f->id, 'date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 10, 'prix_achat_unitaire' => '100 000']],
        ] + $reglement)->assertSessionHasNoErrors();
        $a = $this->dans($b, fn () => Approvisionnement::with('lignes')->first());

        return [$a, $a->lignes->first(), $p, $f];
    }

    private function retourner($a, $ligne, float $quantite, string $mode = 'avoir_fournisseur')
    {
        return $this->post("/approvisionnements/{$a->id}/retours", [
            'quantites' => [$ligne->id => $quantite], 'motif' => 'Produit défectueux', 'mode_remboursement' => $mode,
        ]);
    }

    public function test_retour_sur_reception_a_credit_reduit_la_dette_et_le_stock(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$a, $l, $p, $f] = $this->reception($b, $admin, ['reglement' => 'credit']);

        $this->retourner($a, $l, 3)->assertSessionHas('succes', fn ($m) => str_contains($m, 'déduit de ce que vous devez'))
            ->assertSessionHas('bon_retour');

        $a->refresh();
        $this->assertSame(300_000, $a->montant_retourne);
        $this->assertSame(700_000, $a->resteAPayer());
        $this->assertSame(700_000, $this->dans($b, fn () => $f->soldeDu()));
        $this->assertEquals(7, $this->dans($b, fn () => Produit::find($p->id)->stock));
        $this->assertEquals(3, $l->fresh()->quantite_retournee);
        $mvt = $this->dans($b, fn () => MouvementStock::where('type', 'retour_fournisseur')->first());
        $this->assertEquals(-3, $mvt->quantite);
        $this->assertSame(0, $this->dans($b, fn () => PaiementFournisseur::count()), 'aucun argent ne bouge');

        $this->get("/approvisionnements/{$a->id}")->assertOk()->assertSee('Renvoyé au fournisseur')->assertSee('700 000 GNF')
            ->assertSee('Retours au fournisseur')->assertSee('3 renvoyé(s)');
        $this->get('/dettes-fournisseurs')->assertSee('retourné 300 000 GNF');

        // Bon de retour imprimable
        $r = $this->dans($b, fn () => RetourFournisseur::first());
        $this->get("/retours-fournisseur/{$r->id}/bon")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_on_ne_renvoie_ni_plus_que_recu_ni_plus_que_le_stock(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$a, $l, $p] = $this->reception($b, $admin, ['reglement' => 'credit']);

        $this->retourner($a, $l, 11)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'seulement 10'));
        $this->retourner($a, $l, 4)->assertSessionHas('succes');
        $this->retourner($a, $l, 7)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'seulement 6'));

        // 5 déjà vendus : il n'en reste qu'un en rayon
        $this->dans($b, fn () => app(\App\Services\StockService::class)->mouvement(Produit::find($p->id), 'ajustement', -5), $admin);
        $this->retourner($a, $l, 2)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Stock insuffisant'));
        $this->assertSame(400_000, $a->fresh()->montant_retourne, 'rien n\'a changé après les refus');

        $this->post("/approvisionnements/{$a->id}/retours", ['quantites' => [$l->id => 0], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'au moins une quantité'));
    }

    public function test_reception_payee_le_fournisseur_rembourse_en_especes_dans_la_caisse(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$a, $l] = $this->reception($b, $admin, ['reglement' => 'comptant', 'mode_reglement' => 'especes']);
        $avant = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']);

        $this->retourner($a, $l, 2, 'especes')->assertSessionHas('succes', fn ($m) => str_contains($m, 'vous rembourse 200 000 GNF'));

        $a->refresh();
        $this->assertSame(800_000, $a->montant_paye);
        $this->assertSame(0, $a->resteAPayer());
        $apres = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']);
        $this->assertSame(200_000, $apres - $avant, 'l\'argent rendu par le fournisseur rentre dans le tiroir');

        // Trésorerie : une entrée, pas une sortie négative
        $mvts = $this->dans($b, fn () => app(Tresorerie::class)->lignes('caisse', now()->subDay(), now()->addMinute()));
        $ligne = collect($mvts)->first(fn ($m) => str_contains($m['libelle'], 'Remboursement fournisseur'));
        $this->assertSame(200_000, $ligne['entree']);
        $this->assertSame(0, $ligne['sortie']);
    }

    public function test_avoir_fournisseur_sert_a_regler_une_prochaine_livraison(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$a, $l, $p, $f] = $this->reception($b, $admin, ['reglement' => 'partiel', 'montant_paye' => '600 000', 'mode_reglement' => 'orange_money']);

        // 5 renvoyés = 500 000 : 400 000 effacent la dette, 100 000 deviennent un avoir
        $this->retourner($a, $l, 5)->assertSessionHas('succes', fn ($m) => str_contains($m, '100 000 GNF en avoir'));
        $a->refresh();
        $this->assertSame(0, $a->resteAPayer());
        $this->assertSame(500_000, $a->montant_paye);
        $this->assertSame(100_000, $this->dans($b, fn () => $f->avoirDisponible()));
        $r = $this->dans($b, fn () => RetourFournisseur::first());
        $this->assertSame([400_000, 100_000], [$r->deduit, $r->rembourse]);

        // Nouvelle livraison à crédit : l'avoir la règle en partie, sans toucher à la caisse
        $this->post('/approvisionnements', [
            'fournisseur_id' => $f->id, 'date_appro' => now()->toDateString(), 'reglement' => 'credit',
            'lignes' => [['produit_id' => $p->id, 'quantite' => 3, 'prix_achat_unitaire' => '100 000']],
        ])->assertSessionHasNoErrors();
        $this->get('/dettes-fournisseurs')->assertSee('Avoirs chez vos fournisseurs')->assertSee('Avoir fournisseur (100 000 GNF disponible)');

        $this->post("/fournisseurs/{$f->id}/reglements", ['montant' => '150 000', 'mode' => 'avoir_fournisseur'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Avoir disponible'));
        $caisseAvant = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']);
        $this->post("/fournisseurs/{$f->id}/reglements", ['montant' => '100 000', 'mode' => 'avoir_fournisseur'])->assertSessionHas('succes');

        $this->assertSame(0, $this->dans($b, fn () => $f->avoirDisponible()));
        $this->assertSame(200_000, $this->dans($b, fn () => $f->soldeDu()));
        $this->assertSame($caisseAvant, $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']));
    }

    public function test_reception_sans_fournisseur_et_isolation(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 0);
        $this->actingAs($admin)->post('/approvisionnements', ['date_appro' => now()->toDateString(), 'reglement' => 'comptant',
            'lignes' => [['produit_id' => $p->id, 'quantite' => 2, 'prix_achat_unitaire' => '10 000']]]);
        $a = $this->dans($b, fn () => Approvisionnement::with('lignes')->first());
        $this->retourner($a, $a->lignes->first(), 1)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'aucun fournisseur'));
        $this->get("/approvisionnements/{$a->id}")->assertDontSee('Renvoyer de la marchandise');

        // Une autre boutique ne voit ni la réception ni ses bons
        [$b2, $admin2] = $this->creerBoutique('Autre boutique', 'autre@test.gn');
        $this->actingAs($admin2)->post("/approvisionnements/{$a->id}/retours", ['quantites' => [1 => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertNotFound();
    }
}
