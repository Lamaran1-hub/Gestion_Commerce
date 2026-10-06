<?php

namespace Tests\Feature;

use App\Models\Vente;
use App\Services\CaisseService;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Ventes faites sans connexion puis envoyées au serveur. */
class CaisseHorsLigneTest extends TestCase
{
    private function vente(int $produitId, array $extra = []): array
    {
        return $extra + [
            'uuid' => (string) Str::uuid(), 'cree_le' => now()->subHours(2)->toIso8601String(),
            'lignes' => [['produit_id' => $produitId, 'quantite' => 2, 'conditionnement' => 0, 'prix_unitaire' => 300_000]],
            'mode' => 'especes', 'montant_recu' => 600_000,
        ];
    }

    public function test_vente_envoyee_une_seule_fois_a_sa_date_reelle(): void
    {
        $this->travelTo(today()->setTime(12, 0));   // la vente d'il y a 2 h doit tomber le même jour (sinon échec entre minuit et 2 h)
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $v = $this->vente($p->id);

        $r = $this->actingAs($admin)->postJson('/caisse/synchroniser', ['ventes' => [$v]])->assertOk();
        $this->assertSame('ok', $r->json('resultats.0.statut'));
        $this->assertSame([], $r->json('resultats.0.alertes'));
        $vente = $this->dans($b, fn () => Vente::where('uuid_hors_ligne', $v['uuid'])->first());
        $this->assertSame(now()->subHours(2)->format('Y-m-d H'), $vente->date_vente->format('Y-m-d H'));
        $this->assertSame($vente->date_vente->toDateTimeString(), $vente->paiements->first()->date_paiement->toDateTimeString());
        $this->assertNotNull($vente->synchronisee_le);
        $this->assertEquals(8, $p->fresh()->stock);
        // Espèces comptées dans la caisse du jour
        $this->assertSame(600_000, $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']));

        // Réseau instable : la même vente renvoyée n'est pas doublée
        $this->postJson('/caisse/synchroniser', ['ventes' => [$v]])->assertJsonPath('resultats.0.statut', 'deja')->assertJsonPath('resultats.0.numero', $vente->numero);
        $this->assertSame(1, $this->dans($b, fn () => Vente::count()));
    }

    public function test_marchandise_partie_stock_negatif_et_prix_different_signales(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 1); // un seul sac en stock, deux vendus hors connexion à l'ancien prix
        $v = $this->vente($p->id, ['lignes' => [['produit_id' => $p->id, 'quantite' => 2, 'prix_unitaire' => 290_000]], 'montant_recu' => 580_000]);

        $r = $this->actingAs($admin)->postJson('/caisse/synchroniser', ['ventes' => [$v]])->assertJsonPath('resultats.0.statut', 'ok');
        $alertes = implode(' | ', $r->json('resultats.0.alertes'));
        $this->assertStringContainsString('tarif actuel', $alertes);
        $this->assertStringContainsString('Stock négatif', $alertes);
        $this->assertEquals(-1, $p->fresh()->stock);
        $this->assertSame(580_000, $this->dans($b, fn () => Vente::first())->total_ttc, 'le client a payé le prix affiché');
    }

    public function test_refus_gardes_sur_l_appareil(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $b->update(['periode_verrouillee_jusquau' => now()->subDays(3)->toDateString()]);
        $ventes = [
            $this->vente($p->id, ['montant_recu' => 100_000]),                                        // pas payée entièrement
            $this->vente($p->id, ['cree_le' => now()->subDays(10)->toIso8601String()]),                // trop ancienne
            $this->vente($p->id, ['cree_le' => now()->subDays(4)->toIso8601String()]),                 // période clôturée
            $this->vente($p->id),                                                                     // correcte
        ];
        $r = $this->actingAs($admin)->postJson('/caisse/synchroniser', ['ventes' => $ventes])->assertOk();
        $parUuid = collect($r->json('resultats'))->keyBy('uuid');
        $this->assertStringContainsString('crédit', $parUuid[$ventes[0]['uuid']]['message']);
        $this->assertStringContainsString('7 jours', $parUuid[$ventes[1]['uuid']]['message']);
        $this->assertStringContainsString('clôturée', $parUuid[$ventes[2]['uuid']]['message']);
        $this->assertSame('ok', $parUuid[$ventes[3]['uuid']]['statut']);
        $this->assertSame(1, $this->dans($b, fn () => Vente::count()));
    }

    public function test_ping_et_session_expiree(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->getJson('/caisse/ping')->assertUnauthorized();
        $this->actingAs($admin)->getJson('/caisse/ping')->assertOk()->assertJsonStructure(['jeton']);
        $this->get('/caisse')->assertOk()->assertSee('data-url-sync', false)->assertSee('hors-ligne.js', false);
        $this->assertFileExists(public_path('sw.js'));
        $this->assertFileExists(public_path('hors-ligne.html'));
    }
}
