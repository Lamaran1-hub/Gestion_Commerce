<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Vente;
use App\Services\ResumeQuotidien;
use Tests\TestCase;

/** Échéance des ventes à crédit : date promise par le client, retards, report, relance. */
class EcheanceCreditTest extends TestCase
{
    /** Vente de 300 000 GNF payée 100 000 : 200 000 à crédit. */
    private function venteACredit($b, $admin, $client, array $extra = [])
    {
        $p = $this->produit($b, [], 20);

        return $this->actingAs($admin)->post('/ventes', $extra + ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]],
            'mode' => 'especes', 'montant_recu' => '100 000']);
    }

    /** Vente à crédit faite il y a $jours jours (directement par le service : pas de session HTTP dans le passé). */
    private function creditAuPasse($b, $admin, $client, int $jours): void
    {
        $p = $this->produit($b, [], 20);
        $this->travel(-$jours)->days();
        $this->dans($b, fn () => app(\App\Services\VenteService::class)->creer(['client_id' => $client->id,
            'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 100_000]), $admin);
        $this->travelBack();
    }

    public function test_echeance_saisie_a_la_caisse_ou_delai_par_defaut(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Diallo', 'telephone' => '620112233']));

        // Sans date : délai de la boutique, 30 jours à défaut
        $this->venteACredit($b, $admin, $client)->assertRedirect();
        $this->assertSame(now()->addDays(30)->toDateString(), $this->dans($b, fn () => Vente::latest('id')->first())->echeance->toDateString());

        // Date choisie à la caisse ; elle est imprimée sur le reçu
        $this->venteACredit($b, $admin, $client, ['echeance' => now()->addDays(10)->toDateString()])->assertRedirect();
        $v = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->assertSame(now()->addDays(10)->toDateString(), $v->echeance->toDateString());
        $this->get("/ventes/{$v->id}/recu")->assertSee('À payer avant le')->assertSee(now()->addDays(10)->format('d/m/Y'));
        $this->get("/ventes/{$v->id}")->assertSee('Reporter l\'échéance', false);

        // Date passée refusée ; vente payée comptant : pas d'échéance
        $this->venteACredit($b, $admin, $client, ['echeance' => now()->subDay()->toDateString()])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'échéance du crédit doit être comprise'));
        $p = $this->produit($b, ['designation' => 'Sucre'], 5);
        $this->post('/ventes', ['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'echeance' => now()->addDays(5)->toDateString()]);
        $this->assertNull($this->dans($b, fn () => Vente::latest('id')->first())->echeance);
    }

    public function test_credits_en_retard_filtres_reportes_et_bloques(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['delai_credit_jours' => 15]);
        $retard = $this->dans($b, fn () => Client::create(['nom' => 'Retard', 'telephone' => '620000001']));
        $bientot = $this->dans($b, fn () => Client::create(['nom' => 'Bientot', 'telephone' => '620000002']));
        $tard = $this->dans($b, fn () => Client::create(['nom' => 'Tranquille', 'telephone' => '620000003']));

        $this->creditAuPasse($b->fresh(), $admin, $retard, 20);                              // échéance il y a 5 jours
        $this->venteACredit($b->fresh(), $admin, $bientot, ['echeance' => now()->addDays(3)->toDateString()]);
        $this->venteACredit($b->fresh(), $admin, $tard, ['echeance' => now()->addDays(60)->toDateString()]);

        $this->get('/credits')->assertOk()->assertSee('dont')->assertSeeInOrder(['Retard', 'Bientot', 'Tranquille'])
            ->assertSee('en retard de 5 j')->assertSee('dans 3 j');
        $this->get('/credits?vue=retard')->assertSee('Retard')->assertDontSee('Bientot')->assertDontSee('Tranquille');
        $this->get('/credits?vue=semaine')->assertSee('Bientot')->assertDontSee('Tranquille');

        // Le client en retard ne prend plus à crédit
        $this->venteACredit($b->fresh(), $admin, $retard)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'a un crédit en retard'));

        // Il demande un délai : échéance reportée, il peut de nouveau acheter à crédit
        $v = $this->dans($b, fn () => Vente::where('client_id', $retard->id)->first());
        $this->post("/ventes/{$v->id}/echeance", ['echeance' => now()->addDays(10)->toDateString(), 'motif' => 'Salaire le 15'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, now()->addDays(10)->format('d/m/Y')));
        $this->assertFalse($v->fresh()->creditEnRetard());
        $this->venteACredit($b->fresh(), $admin, $retard)->assertRedirect();
        $this->assertSame(0, (int) $this->dans($b, fn () => Vente::enRetard()->count()));

        // Une vente soldée n'a plus d'échéance à reporter
        $comptant = $this->dans($b, fn () => Vente::where('client_id', $tard->id)->first());
        $this->post("/ventes/{$comptant->id}/paiements", ['montant' => '200 000', 'mode' => 'especes']);
        $this->post("/ventes/{$comptant->id}/echeance", ['echeance' => now()->addDays(5)->toDateString()])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'entièrement payée'));
    }

    public function test_rapport_et_export_des_credits_sans_une_requete_par_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $retard = $this->dans($b, fn () => Client::create(['nom' => 'Retard', 'telephone' => '620000001']));
        $this->creditAuPasse($b, $admin, $retard, 40);
        // 60 clients sans dette : ils ne doivent coûter aucune requête de plus
        foreach (range(1, 60) as $i) {
            $this->dans($b, fn () => Client::create(['nom' => 'Sans dette '.$i]));
        }

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $this->actingAs($admin)->get('/rapports/credits/ecran')->assertOk()
            ->assertSee('À payer avant le')->assertSee(now()->subDays(10)->format('d/m/Y'))->assertSee('Dont en retard')->assertSee('200 000');
        $requetes = count(\Illuminate\Support\Facades\DB::getQueryLog());
        $this->assertLessThan(40, $requetes, "rapport des crédits : {$requetes} requêtes");

        \Illuminate\Support\Facades\DB::flushQueryLog();
        $this->get('/clients/export?format=ecran')->assertOk();
        $this->assertLessThan(40, count(\Illuminate\Support\Facades\DB::getQueryLog()), 'export des clients : une requête par client ?');
    }

    public function test_relance_et_resume_du_jour_parlent_de_l_echeance(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Camara', 'telephone' => '620112233']));
        $this->creditAuPasse($b->fresh(), $admin, $client, 40);

        $lien = $this->actingAs($admin)->post("/clients/{$client->id}/relancer")->headers->get('Location');
        $this->assertStringContainsString(rawurlencode('est dépassée'), $lien);

        $r = $this->dans($b, fn () => app(ResumeQuotidien::class)->calculer($b->fresh(), now()));
        $this->assertSame(200_000, $r['credits_retard']);
    }
}
