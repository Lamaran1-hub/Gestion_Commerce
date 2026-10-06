<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Client;
use App\Models\Promotion;
use App\Models\Vente;
use App\Models\VenteEnAttente;
use App\Services\Tresorerie;
use Tests\TestCase;

/** Caisse : paiement mixte, tickets en attente, promotions à durée limitée. */
class CaisseAvanceeTest extends TestCase
{
    public function test_paiement_mixte_especes_et_orange_money(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10); // 300 000

        // 100 000 en Orange Money, le client donne 250 000 en espèces pour le reste (200 000) : 50 000 à rendre
        $this->actingAs($admin)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 250_000,
            'paiements_autres' => [['mode' => 'orange_money', 'montant' => '100 000', 'reference' => 'OM123']]])->assertRedirect();
        $v = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->assertSame(300_000, $v->montant_paye);
        $this->assertSame(['especes' => 200_000, 'orange_money' => 100_000], $v->paiements->pluck('montant', 'mode')->sortKeys()->all());
        $this->assertSame('OM123', $v->paiements->firstWhere('mode', 'orange_money')->reference);
        $s = $this->dans($b, fn () => app(Tresorerie::class)->soldes());
        $this->assertSame(200_000, $s['caisse']['solde']);
        $this->assertSame(100_000, $s['orange_money']['solde']);

        // Un paiement électronique ne rend pas de monnaie
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes',
            'paiements_autres' => [['mode' => 'mtn_momo', 'montant' => 400_000]]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'dépassent'));
        // Mixte avec reste à crédit : client obligatoire
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 0,
            'paiements_autres' => [['mode' => 'orange_money', 'montant' => 100_000]]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'client'));
    }

    public function test_ticket_en_attente_puis_repris_et_vendu(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Sow']));

        $this->actingAs($admin)->post('/caisse/attente', ['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 2]]])
            ->assertRedirect('/caisse')->assertSessionHas('succes', fn ($m) => str_contains($m, '600 000'));
        $this->assertEquals(10, $p->fresh()->stock, 'rien ne sort du stock');
        $t = $this->dans($b, fn () => VenteEnAttente::first());
        $this->get('/caisse')->assertSee('En attente')->assertSee('Sow');

        $this->get("/caisse/attente/{$t->id}")->assertRedirect('/caisse')->assertSessionHasInput('attente_id', $t->id);
        $this->post('/ventes', ['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes', 'attente_id' => $t->id])->assertRedirect();
        $this->assertSame(0, $this->dans($b, fn () => VenteEnAttente::count()), 'ticket supprimé une fois vendu');
        $this->assertEquals(8, $p->fresh()->stock);
    }

    public function test_promotions(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $cat = $this->dans($b, fn () => Categorie::create(['nom' => 'Boissons']));
        $jus = $this->produit($b, ['designation' => 'Jus', 'categorie_id' => $cat->id, 'prix_achat' => 9_000, 'prix_vente' => 10_000], 50);
        $riz = $this->produit($b, [], 10);

        // −20 % sur la catégorie : 8 000, mais plafonné au prix d'achat (9 000) car la vente à perte est interdite
        $this->actingAs($admin)->post('/promotions', ['nom' => 'Fête', 'portee' => 'categorie', 'categorie_id' => $cat->id, 'type' => 'pourcentage',
            'valeur' => 20, 'debut' => now()->toDateString(), 'fin' => now()->addDays(3)->toDateString()])->assertSessionHas('succes');
        // Prix fixe sous le coût sur un produit : refusé tout de suite
        $this->post('/promotions', ['nom' => 'Riz', 'portee' => 'produit', 'produit_id' => $riz->id, 'type' => 'prix', 'valeur' => '200 000',
            'debut' => now()->toDateString(), 'fin' => now()->addDays(3)->toDateString()])->assertSessionHasErrors('valeur');
        $this->post('/promotions', ['nom' => 'Riz', 'portee' => 'produit', 'produit_id' => $riz->id, 'type' => 'prix', 'valeur' => '280 000',
            'debut' => now()->toDateString(), 'fin' => now()->addDays(3)->toDateString()])->assertSessionHas('succes');

        $this->post('/ventes', ['lignes' => [['produit_id' => $jus->id, 'quantite' => 2], ['produit_id' => $riz->id, 'quantite' => 1]], 'mode' => 'especes']);
        $v = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->assertSame(2 * 9_000 + 280_000, $v->total_ttc);
        $this->assertStringContainsString('promo', $v->lignes->firstWhere('produit_id', $riz->id)->designation);
        $this->get('/caisse')->assertSee('"promo":280000', false);

        // Promotion arrêtée ou future : prix normal
        $this->dans($b, fn () => Promotion::query()->update(['actif' => false]));
        $this->post('/ventes', ['lignes' => [['produit_id' => $riz->id, 'quantite' => 1]], 'mode' => 'especes']);
        $this->assertSame(300_000, $this->dans($b, fn () => Vente::latest('id')->first())->total_ttc);
        $this->get('/promotions')->assertOk()->assertSee('Arrêtée');
    }
}
