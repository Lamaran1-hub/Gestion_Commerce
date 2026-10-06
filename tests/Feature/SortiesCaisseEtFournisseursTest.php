<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Depense;
use App\Models\Fournisseur;
use App\Services\CaisseService;
use App\Services\VenteService;
use Tests\TestCase;

class SortiesCaisseEtFournisseursTest extends TestCase
{
    private function reception($b, $admin, array $reglement, int $quantite = 10, ?int $fournisseurId = null)
    {
        $p = $this->produit($b, [], 0);

        return $this->actingAs($admin)->post('/approvisionnements', [
            'fournisseur_id' => $fournisseurId, 'date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => $quantite, 'prix_achat_unitaire' => '100 000']],
        ] + $reglement);
    }

    // ---------- Dépenses en espèces et caisse ----------

    public function test_depense_en_especes_deduite_de_la_caisse_et_figee_apres_cloture(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);

        $this->actingAs($admin)->post('/depenses', ['motif' => 'Transport', 'montant' => '50 000', 'date_depense' => now()->toDateString(), 'mode' => 'especes']);
        $this->post('/depenses', ['motif' => 'Loyer', 'montant' => '500 000', 'date_depense' => now()->toDateString(), 'mode' => 'virement']);

        $bilan = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
        $this->assertSame(250_000, $bilan['especes_theoriques'], '300 000 encaissés − 50 000 de dépense en espèces (le virement ne compte pas)');

        $this->post('/caisse/cloture', ['especes_comptees' => '250 000'])->assertRedirect();
        $depense = $this->dans($b, fn () => Depense::where('mode', 'especes')->first());

        // Figée après clôture ; nouvelle dépense en espèces refusée, par virement acceptée
        $this->delete("/depenses/{$depense->id}")->assertSessionHas('erreur', fn ($m) => str_contains($m, 'caisse déjà clôturée'));
        $this->post('/depenses', ['motif' => 'Eau', 'montant' => '10 000', 'date_depense' => now()->toDateString(), 'mode' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'caisse est clôturée'));
        $this->post('/depenses', ['motif' => 'Eau', 'montant' => '10 000', 'date_depense' => now()->toDateString(), 'mode' => 'orange_money'])
            ->assertSessionHas('succes');
    }

    // ---------- Dettes fournisseurs ----------

    public function test_reception_a_credit_cree_une_dette_avec_echeance(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci']));

        // À crédit sans fournisseur : refusé
        $this->reception($b, $admin, ['reglement' => 'credit'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'fournisseur'));

        $this->reception($b, $admin, ['reglement' => 'partiel', 'montant_paye' => '400 000', 'mode_reglement' => 'orange_money'], 10, $f->id)
            ->assertSessionHasNoErrors()->assertRedirect();
        $a = $this->dans($b, fn () => Approvisionnement::first());
        $this->assertSame(1_000_000, $a->total);
        $this->assertSame(400_000, $a->montant_paye);
        $this->assertSame(now()->addDays(30)->toDateString(), $a->echeance->toDateString());
        $this->assertSame(600_000, $this->dans($b, fn () => $f->soldeDu()));

        $this->get('/dettes-fournisseurs')->assertOk()->assertSee('Sodeci')->assertSee('600 000 GNF');
        $this->get("/approvisionnements/{$a->id}")->assertSee('Reste 600 000 GNF');
    }

    public function test_reglement_impute_sur_les_plus_anciennes_et_jamais_au_dela_de_la_dette(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci']));
        $this->reception($b, $admin, ['reglement' => 'credit'], 3, $f->id);   // 300 000
        $this->reception($b, $admin, ['reglement' => 'credit'], 5, $f->id);   // 500 000
        [$a1, $a2] = $this->dans($b, fn () => Approvisionnement::orderBy('id')->get()->all());

        $this->post("/fournisseurs/{$f->id}/reglements", ['montant' => '900 000', 'mode' => 'especes'])->assertSessionHas('erreur');
        $this->post("/fournisseurs/{$f->id}/reglements", ['montant' => '400 000', 'mode' => 'especes'])->assertSessionHas('succes');

        $this->assertSame(300_000, $a1->fresh()->montant_paye, 'la plus ancienne est soldée en premier');
        $this->assertSame(100_000, $a2->fresh()->montant_paye);
        $this->assertSame(400_000, $this->dans($b, fn () => $f->soldeDu()));

        // Réglé en espèces : sort du tiroir
        $bilan = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
        $this->assertSame(400_000, $bilan['fournisseurs_especes']);
        $this->assertSame(-400_000, $bilan['especes_theoriques']);
    }

    public function test_echeances_depassees_signalees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci']));
        $this->reception($b, $admin, ['reglement' => 'credit', 'echeance' => now()->toDateString()], 2, $f->id);
        $this->travel(2)->days();
        session()->put('derniere_activite', now()->timestamp);

        $a = $this->dans($b, fn () => Approvisionnement::first());
        $this->assertTrue($a->enRetard());
        $this->actingAs($admin)->get('/dettes-fournisseurs')->assertSee('en retard');
        $r = app(\App\Services\ResumeQuotidien::class)->calculer($b, now()->subDay());
        $this->assertSame(200_000, $r['dettes_fournisseurs_retard']);
    }
}
