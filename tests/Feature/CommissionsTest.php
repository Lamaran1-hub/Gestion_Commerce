<?php

namespace Tests\Feature;

use App\Models\CommissionVersee;
use App\Models\Depense;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\CaisseService;
use App\Services\VenteService;
use Carbon\Carbon;
use Tests\TestCase;

/** Commissions des vendeurs : relevé mensuel, versement (dépense « Salaires »), historique, export. */
class CommissionsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Boutique, vendeuse à 2 % et 2 ventes de 300 000 HT en août 2026. */
    private function preparer(): array
    {
        Carbon::setTestNow('2026-09-15 10:00');
        [$b, $admin] = $this->creerBoutique();
        $awa = User::create(['boutique_id' => $b->id, 'role_id' => Role::withoutGlobalScopes()->where(['boutique_id' => $b->id, 'nom' => 'Vendeur'])->value('id'),
            'prenom' => 'Awa', 'nom' => 'Camara', 'email' => 'awa@test.gn', 'password' => 'secret123', 'commission_pct' => 2]);
        $p = $this->produit($b, [], 20);
        foreach ([1, 1] as $q) {
            $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => $q]], 'mode' => 'especes']), $awa);
            Vente::withoutGlobalScopes()->whereKey($v->id)->update(['date_vente' => '2026-08-20 11:00:00']);
        }

        return [$b, $admin, $awa];
    }

    public function test_releve_du_mois_et_versement_enregistre_en_depense(): void
    {
        [$b, $admin, $awa] = $this->preparer();

        $this->actingAs($admin)->get('/commissions?mois=2026-08')->assertOk()
            ->assertSee('Awa Camara')->assertSee('600 000')->assertSee('12 000')->assertSee('Verser');

        $this->post('/commissions', ['user_id' => $awa->id, 'mois' => '2026-08', 'mode' => 'especes', 'reference' => 'PAIE-08'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, '12 000 GNF'));

        $versement = CommissionVersee::withoutGlobalScopes()->sole();
        $this->assertSame(12_000, $versement->montant);
        $this->assertSame(600_000, $versement->ca_ht);
        $depense = Depense::withoutGlobalScopes()->findOrFail($versement->depense_id);
        $this->assertSame('Salaires', $depense->categorie);
        $this->assertSame(12_000, $depense->montant);

        // Payée en espèces : elle sort de la caisse du jour
        $bilan = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
        $this->assertSame(12_000, $bilan['depenses_especes']);

        // Montant figé : un changement de taux ne réécrit pas le passé
        $awa->update(['commission_pct' => 10]);
        $this->get('/commissions?mois=2026-08')->assertSee('12 000')->assertSee('Versée')->assertDontSee('60 000');

        // Pas de double versement
        $this->post('/commissions', ['user_id' => $awa->id, 'mois' => '2026-08', 'mode' => 'especes'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà versée'));
        $this->assertSame(1, CommissionVersee::withoutGlobalScopes()->count());
    }

    public function test_mois_en_cours_provisoire(): void
    {
        [, $admin, $awa] = $this->preparer();

        $this->actingAs($admin)->get('/commissions?mois=2026-09')->assertOk()->assertSee('provisoires')->assertDontSee('>Verser</button>', false);
        $this->post('/commissions', ['user_id' => $awa->id, 'mois' => '2026-09', 'mode' => 'especes'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'pas terminé'));
        $this->assertSame(0, CommissionVersee::withoutGlobalScopes()->count());
    }

    public function test_annulation_par_l_administrateur_et_depense_protegee(): void
    {
        [$b, $admin, $awa] = $this->preparer();
        $this->actingAs($admin)->post('/commissions', ['user_id' => $awa->id, 'mois' => '2026-08', 'mode' => 'orange_money']);
        $versement = CommissionVersee::withoutGlobalScopes()->sole();

        // La dépense ne se supprime pas depuis la page Dépenses
        $this->delete("/depenses/{$versement->depense_id}")->assertSessionHas('erreur', fn ($m) => str_contains($m, 'page Commissions'));
        $this->assertNotNull(Depense::withoutGlobalScopes()->find($versement->depense_id));

        // Un vendeur ne peut pas annuler (et n'a pas accès à la page)
        $this->actingAs($awa)->delete("/commissions/{$versement->id}")->assertForbidden();

        $this->actingAs($admin)->delete("/commissions/{$versement->id}")->assertSessionHas('succes');
        $this->assertSame(0, CommissionVersee::withoutGlobalScopes()->count());
        $this->assertNull(Depense::withoutGlobalScopes()->find($versement->depense_id));
    }

    public function test_export_et_historique(): void
    {
        [, $admin, $awa] = $this->preparer();
        $this->actingAs($admin)->post('/commissions', ['user_id' => $awa->id, 'mois' => '2026-08', 'mode' => 'especes']);

        $this->get('/commissions/export?mois=2026-08&format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/commissions/export?mois=2026-08&format=ecran')->assertOk()->assertDownload();   // format inconnu : Excel
        $this->get('/commissions/export?mois=2026-08&format=excel')->assertOk()->assertDownload();
        $this->get('/commissions')->assertOk()->assertSee('Historique des versements')->assertSee('Août 2026');
    }
}
