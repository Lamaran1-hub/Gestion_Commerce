<?php

namespace Tests\Feature;

use App\Models\Depense;
use App\Models\Fournisseur;
use App\Models\JournalActivite;
use App\Models\Vente;
use App\Services\Reapprovisionnement;
use App\Services\VenteService;
use Tests\TestCase;

/** « Quoi commander ? » (réapprovisionnement, produits dormants) et verrouillage des périodes clôturées. */
class ReapproEtVerrouillageTest extends TestCase
{
    private function vendre($b, $admin, $p, float $quantite, int $ilYaJours = 0): Vente
    {
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => $quantite]], 'mode' => 'especes']), $admin);
        if ($ilYaJours) {
            Vente::withoutGlobalScopes()->whereKey($v->id)->update(['date_vente' => now()->subDays($ilYaJours)]);
        }

        return $v->fresh();
    }

    public function test_suggestion_calculee_sur_le_rythme_de_vente(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci', 'telephone' => '620000000']));
        // 40 sacs vendus en 30 jours (1,33 / jour), il en reste 10 : 7 jours de stock, moins que les 14 voulus
        $riz = $this->produit($b, ['fournisseur_id' => $f->id], 50);
        $this->vendre($b, $admin, $riz, 40, 5);
        // Produit qui tient largement : pas de suggestion
        $huile = $this->produit($b, ['designation' => 'Huile 5 L', 'prix_achat' => 80_000, 'prix_vente' => 95_000], 100);
        $this->vendre($b, $admin, $huile, 3, 2);
        // Produit jamais vendu mais sous le seuil : remonté au double du seuil
        $sucre = $this->produit($b, ['designation' => 'Sucre', 'seuil_alerte' => 5, 'prix_achat' => 10_000, 'prix_vente' => 12_000], 2);

        $s = $this->dans($b, fn () => app(Reapprovisionnement::class)->suggestions()->keyBy(fn ($x) => $x['produit']->id));
        $this->assertFalse($s->has($huile->id));
        $this->assertSame(7, $s[$riz->id]['jours_restants']);
        $this->assertEquals(9, $s[$riz->id]['quantite'], '1,33 × 14 − 10 = 8,7 → 9 sacs');
        $this->assertSame(9 * 250_000, $s[$riz->id]['cout']);
        $this->assertEquals(8, $s[$sucre->id]['quantite'], '5 × 2 − 2');

        $this->actingAs($admin)->get('/stock/a-commander')->assertOk()->assertSee('Sodeci')->assertSee('Riz 25 kg')->assertSee('Enregistrer la commande');   // le WhatsApp part de la commande enregistrée (avec son numéro)
    }

    public function test_suggestion_arrondie_au_carton_et_produits_dormants(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $eau = $this->produit($b, ['designation' => 'Eau', 'unite' => 'bouteille', 'prix_achat' => 4_000, 'prix_vente' => 5_000,
            'conditionnement' => 'carton', 'qte_conditionnement' => 12, 'prix_conditionnement' => 54_000], 40);
        $this->vendre($b, $admin, $eau, 30, 3); // 1 / jour, reste 10
        $dormant = $this->produit($b, ['designation' => 'Parapluie', 'prix_achat' => 20_000, 'prix_vente' => 30_000], 5);
        $this->vendre($b, $admin, $dormant, 1, 90);

        $r = $this->dans($b, fn () => app(Reapprovisionnement::class));
        $s = $this->dans($b, fn () => $r->suggestions()->firstWhere('produit.id', $eau->id));
        $this->assertSame(1, $s['conditionnements'], '14 − 10 = 4 bouteilles → 1 carton');
        $this->assertEquals(12, $s['quantite']);

        $d = $this->dans($b, fn () => $r->dormants());
        $this->assertSame([$dormant->id], $d->pluck('produit.id')->all());
        $this->assertSame(4 * 20_000, $d->first()['valeur']);
    }

    public function test_bon_de_commande_pdf(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);
        $this->actingAs($admin)->post('/stock/bon-de-commande', ['quantites' => [$p->id => 5]])
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_periode_cloturee_bloque_les_modifications(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 50);
        $ancienne = $this->vendre($b, $admin, $p, 2, 40);
        $b->update(['periode_verrouillee_jusquau' => now()->subDays(20)->toDateString()]);

        $this->actingAs($admin)->post("/ventes/{$ancienne->id}/annuler", ['motif' => 'Erreur'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'clôturée'));
        $this->assertSame('validee', $ancienne->fresh()->statut);

        $this->post("/ventes/{$ancienne->id}/retours", ['quantites' => [$ancienne->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'clôturée'));

        $this->post('/depenses', ['motif' => 'Loyer', 'montant' => 100_000, 'mode' => 'virement', 'date_depense' => now()->subDays(25)->toDateString()])
            ->assertSessionHas('erreur');
        $this->post('/approvisionnements', ['date_appro' => now()->subDays(25)->toDateString(), 'reglement' => 'comptant', 'mode_reglement' => 'especes',
            'lignes' => [['produit_id' => $p->id, 'quantite' => 5, 'prix_achat_unitaire' => 250_000]]])->assertSessionHas('erreur');
        $this->assertEquals(48, $p->fresh()->stock);

        // Une dépense ancienne ne peut plus être modifiée ni supprimée
        $dep = $this->dans($b, fn () => Depense::create(['motif' => 'Transport', 'montant' => 5_000, 'mode' => 'virement', 'date_depense' => now()->subDays(30)->toDateString(), 'user_id' => $admin->id]));
        $this->delete("/depenses/{$dep->id}")->assertSessionHas('erreur');
        $this->assertNotNull($dep->fresh());

        // La période ouverte fonctionne normalement
        $this->post('/depenses', ['motif' => 'Loyer', 'montant' => 100_000, 'mode' => 'virement', 'date_depense' => now()->toDateString()])->assertSessionHas('succes');
        $recente = $this->vendre($b, $admin, $p, 1);
        $this->post("/ventes/{$recente->id}/annuler", ['motif' => 'Erreur'])->assertSessionHas('succes');
    }

    public function test_seul_l_administrateur_cloture_la_periode(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $jour = now()->subMonth()->endOfMonth()->toDateString();
        $this->actingAs($admin)->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1F6F50', 'periode_verrouillee_jusquau' => $jour])
            ->assertSessionHas('succes');
        $this->assertSame($jour, $b->fresh()->periode_verrouillee_jusquau->toDateString());
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', 'Période clôturée%')->exists()));

        // Pas de verrou dans le futur (il bloquerait la journée en cours)
        $this->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1F6F50', 'periode_verrouillee_jusquau' => now()->toDateString()])
            ->assertSessionHasErrors('periode_verrouillee_jusquau');
    }
}
