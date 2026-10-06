<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Client;
use App\Services\ApprovisionnementService;
use App\Services\Peremption;
use App\Services\ReleveClient;
use App\Services\VenteService;
use Tests\TestCase;

/** Dates de péremption (lots estimés FIFO) ; relevé de compte et relance des clients débiteurs. */
class PeremptionEtRelevesTest extends TestCase
{
    private function recevoir($b, $admin, $p, float $q, ?string $peremption, int $ilYaJours): void
    {
        $this->dans($b, fn () => app(ApprovisionnementService::class)->creer([
            'date_appro' => now()->subDays($ilYaJours)->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => $q, 'prix_achat_unitaire' => $p->prix_achat, 'date_peremption' => $peremption]],
        ]), $admin);
    }

    public function test_lots_estimes_premier_entre_premier_sorti(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $lait = $this->produit($b, ['designation' => 'Lait', 'unite' => 'boîte', 'prix_achat' => 10_000, 'prix_vente' => 12_000], 0);
        $this->recevoir($b, $admin, $lait, 20, now()->subDays(2)->toDateString(), 60);   // lot ancien, déjà périmé
        $this->recevoir($b, $admin, $lait, 30, now()->addDays(10)->toDateString(), 10);  // lot récent, périme bientôt
        $this->recevoir($b, $admin, $lait, 10, now()->addYear()->toDateString(), 1);     // très récent, pas d'alerte
        // 45 vendues : il reste 15 → les 10 du dernier lot + 5 du lot à 10 jours ; le lot périmé est écoulé
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $lait->id, 'quantite' => 45]], 'mode' => 'especes']), $admin);

        $lots = $this->dans($b, fn () => app(Peremption::class)->lots(30));
        $this->assertCount(1, $lots);
        $this->assertEquals(5, $lots[0]['quantite']);
        $this->assertSame(10, $lots[0]['jours']);
    }

    public function test_retrait_d_un_lot_perime(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $lait = $this->produit($b, ['designation' => 'Lait', 'prix_achat' => 10_000, 'prix_vente' => 12_000], 0);
        $this->recevoir($b, $admin, $lait, 8, now()->subDay()->toDateString(), 40);

        $this->actingAs($admin)->get('/stock/peremptions')->assertOk()->assertSee('Déjà périmés')->assertSee('Lait');
        $this->post("/stock/peremptions/{$lait->id}/retirer", ['quantite' => 8])->assertSessionHas('succes', fn ($m) => str_contains($m, '80 000 GNF'));
        $this->assertEquals(0, $lait->fresh()->stock);
        $this->assertTrue($this->dans($b, fn () => \App\Models\MouvementStock::where('type', 'peremption')->where('quantite', -8)->exists()));
        $this->assertCount(0, $this->dans($b, fn () => app(Peremption::class)->lots(30)));
    }

    public function test_date_de_peremption_anterieure_a_la_reception_refusee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 0);
        $this->actingAs($admin)->post('/approvisionnements', ['date_appro' => now()->toDateString(), 'reglement' => 'comptant', 'mode_reglement' => 'especes',
            'lignes' => [['produit_id' => $p->id, 'quantite' => 5, 'prix_achat_unitaire' => 250_000, 'date_peremption' => now()->subDay()->toDateString()]]])
            ->assertSessionHasErrors('lignes.0.date_peremption');
        $this->assertSame(0, $this->dans($b, fn () => Approvisionnement::count()));
    }

    public function test_releve_de_compte_solde_egal_au_reste_a_payer(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Diallo', 'prenom' => 'Mamadou', 'telephone' => '622000000']));
        $p = $this->produit($b, [], 50);
        $vs = app(VenteService::class);
        // Achat à crédit de 3 sacs (900 000), versement 200 000, retour d'1 sac, second achat payé
        $v1 = $this->dans($b, fn () => $vs->creer(['client_id' => $c->id, 'mode' => 'especes', 'montant_recu' => 0, 'lignes' => [['produit_id' => $p->id, 'quantite' => 3]]]), $admin);
        $this->actingAs($admin)->post("/credits/{$c->id}", ['montant' => 200_000, 'mode' => 'especes']);
        $this->post("/ventes/{$v1->id}/retours", ['quantites' => [$v1->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes']);
        $this->dans($b, fn () => $vs->creer(['client_id' => $c->id, 'mode' => 'especes', 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]), $admin);

        $r = $this->dans($b, fn () => app(ReleveClient::class)->construire($c->fresh()));
        $du = $this->dans($b, fn () => $c->fresh()->soldeDu());
        $this->assertSame(400_000, $du);
        $this->assertSame($du, $r['solde_final']);
        $this->assertSame(0, $r['solde_initial']);
        $this->assertSame(1_200_000, $r['total_debit']);

        $this->get("/clients/{$c->id}/releve")->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_relance_whatsapp_datee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Camara', 'telephone' => '622000000']));
        $p = $this->produit($b);
        $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'mode' => 'especes', 'montant_recu' => 0, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]), $admin);

        $r = $this->actingAs($admin)->post("/clients/{$c->id}/relancer");
        $r->assertRedirect();
        $this->assertStringContainsString('wa.me/224622000000', $r->headers->get('Location'));
        $message = rawurldecode($r->headers->get('Location'));
        $this->assertStringContainsString('Camara', $message);
        $this->assertMatchesRegularExpression('/300.000/u', $message);
        $this->assertNotNull($c->fresh()->derniere_relance_le);
        $this->get('/credits')->assertSee('relancé');
    }
}
