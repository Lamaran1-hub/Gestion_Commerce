<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Vente;
use App\Services\Tresorerie;
use App\Services\VenteService;
use Tests\TestCase;

/** Programme de fidélité : gain sur les sommes payées, utilisation en caisse, retours et annulations. */
class FideliteTest extends TestCase
{
    private function preparer(float $taux = 2, int $minimum = 0): array
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['fidelite_taux' => $taux, 'fidelite_minimum' => $minimum]);
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Bah', 'telephone' => '621000000']));
        $p = $this->produit($b, [], 50); // 300 000 le sac

        return [$b->fresh(), $admin, $c, $p];
    }

    public function test_points_gagnes_seulement_sur_ce_qui_est_paye(): void
    {
        [$b, $admin, $c, $p] = $this->preparer();
        $vs = app(VenteService::class);
        $this->dans($b, fn () => $vs->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->assertSame(6_000, $c->fresh()->points, '2 % de 300 000');

        // À crédit : rien tant que ce n'est pas payé, puis au fil des versements
        $this->dans($b, fn () => $vs->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 0]), $admin);
        $this->assertSame(6_000, $c->fresh()->points);
        $this->actingAs($admin)->post("/credits/{$c->id}", ['montant' => 100_000, 'mode' => 'orange_money']);
        $this->assertSame(8_000, $c->fresh()->points);

        // Client comptoir : pas de points ; programme désactivé : pas de points
        $this->dans($b, fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $b->update(['fidelite_taux' => 0]);
        $this->dans($b->fresh(), fn () => $vs->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->assertSame(8_000, $c->fresh()->points);
    }

    public function test_utilisation_en_caisse_hors_tresorerie(): void
    {
        [$b, $admin, $c, $p] = $this->preparer(2, 5_000);
        $c->forceFill(['points' => 8_000])->save();

        $this->actingAs($admin)->post('/ventes', ['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]],
            'mode' => 'especes', 'utiliser_points' => 1])->assertRedirect();
        $v = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->assertSame(300_000, $v->montant_paye);
        $this->assertSame(['especes' => 292_000, 'fidelite' => 8_000], $v->paiements->pluck('montant', 'mode')->sortKeys()->all());
        // Les points payés ne rapportent rien : 2 % de 292 000
        $this->assertSame(5_840, $c->fresh()->points);
        $this->assertSame(292_000, $this->dans($b, fn () => app(Tresorerie::class)->soldes()['caisse']['solde']));
        $this->assertSame(0, $this->dans($b, fn () => app(Tresorerie::class)->soldes()['autre']['solde']));

        $this->get("/ventes/{$v->id}/recu?apercu=1")->assertSee('Points de fidélité')->assertSee('5 840 points');
        $this->get('/rapports/resultat/ecran')->assertSee('Points de fidélité utilisés')->assertSee('-8 000 GNF');

        // Sous le minimum : points non utilisables
        $b->update(['fidelite_minimum' => 6_000]);
        $this->actingAs($admin->fresh()); // en production, l'utilisateur et sa boutique sont relus à chaque requête
        $this->post('/ventes', ['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'utiliser_points' => 1]);
        $this->assertSame(5_840 + 6_000, $c->fresh()->points);
    }

    public function test_retour_rend_les_points_et_annulation_reprend_tout(): void
    {
        [$b, $admin, $c, $p] = $this->preparer();
        $c->forceFill(['points' => 8_000])->save();
        $vs = app(VenteService::class);
        $v = $this->dans($b, fn () => $vs->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]],
            'mode' => 'especes', 'utiliser_points' => 1]), $admin);
        $this->assertSame(5_840, $c->fresh()->points);

        // Retour complet : les 8 000 payés en points reviennent en points, seul le reste est rendu en espèces
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, '292 000'));
        $this->assertSame(8_000, $c->fresh()->points);
        $this->assertSame(0, $v->fresh()->montant_paye);

        // Nouvelle vente payée en points, puis annulée : le client retrouve exactement son solde
        $v2 = $this->dans($b, fn () => $vs->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]],
            'mode' => 'especes', 'utiliser_points' => 1]), $admin);
        $this->assertSame(5_840, $c->fresh()->points);
        $this->dans($b, fn () => $vs->annuler($v2, 'Erreur'), $admin);
        $this->assertSame(8_000, $c->fresh()->points);
    }
}
