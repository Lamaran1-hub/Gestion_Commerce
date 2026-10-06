<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\Objectifs;
use App\Services\VenteService;
use Carbon\Carbon;
use Tests\TestCase;

/** Objectifs de vente du mois (boutique, vendeurs) et commissions. */
class ObjectifsTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function vendeur($b, string $email, array $attrs = []): User
    {
        return User::create($attrs + ['boutique_id' => $b->id, 'role_id' => Role::withoutGlobalScopes()->where(['boutique_id' => $b->id, 'nom' => 'Vendeur'])->value('id'),
            'prenom' => 'Awa', 'nom' => 'Camara', 'email' => $email, 'password' => 'secret123']);
    }

    public function test_calcul_de_la_projection_et_du_reste_par_jour(): void
    {
        Carbon::setTestNow('2026-09-10 12:00');   // 10e jour d'un mois de 30 jours
        $s = app(Objectifs::class)->suivi(3_000_000, 1_200_000);

        $this->assertSame(40, $s['pct']);
        $this->assertSame(3_600_000, $s['projection'], '120 000 par jour × 30 jours');
        $this->assertTrue($s['atteindra']);
        $this->assertSame(21, $s['jours_restants'], "Du 10 au 30, aujourd'hui compris");
        $this->assertSame(85_715, $s['par_jour'], '1 800 000 restants sur 21 jours, arrondi au franc supérieur');

        Carbon::setTestNow('2026-09-30 09:00');   // dernier jour : tout reste à faire aujourd'hui
        $dernier = app(Objectifs::class)->suivi(3_000_000, 2_900_000);
        $this->assertSame(1, $dernier['jours_restants']);
        $this->assertSame(100_000, $dernier['par_jour']);

        $sansObjectif = app(Objectifs::class)->suivi(null, 500_000);
        $this->assertNull($sansObjectif['pct']);
    }

    public function test_tableau_de_bord_objectif_classement_et_commissions(): void
    {
        Carbon::setTestNow('2026-09-10 12:00');
        [$b, $admin] = $this->creerBoutique();
        $b->update(['objectif_mensuel' => 3_000_000, 'tva_active' => true, 'tva_taux' => 18]);
        $awa = $this->vendeur($b, 'awa@test.gn', ['objectif_mensuel' => 1_000_000, 'commission_pct' => 2]);
        $p = $this->produit($b, [], 20);   // 300 000 HT → 354 000 TTC

        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $awa);
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);

        $equipe = $this->dans($b, fn () => app(Objectifs::class)->equipe());
        $this->assertSame('awa@test.gn', $equipe->first()['user']->email, 'Meilleur chiffre d\'affaires en tête');
        $this->assertSame(708_000, $equipe->first()['realise']);
        $this->assertSame(12_000, $equipe->first()['commission'], '2 % de 600 000 HT');

        $this->actingAs($admin)->get('/tableau-de-bord')->assertOk()
            ->assertSee('Objectif de septembre')->assertSee('1 062 000')->assertSee('35 % atteint')
            ->assertSee("L'équipe ce mois-ci", false)->assertSee('Awa Camara')->assertSee('Commissions à verser')->assertSee('12 000');

        // À la caisse, la vendeuse voit sa progression
        $this->actingAs($awa)->get('/caisse')->assertOk()->assertSee('Mon mois')->assertSee('70 %');
    }

    public function test_reglage_des_objectifs_et_commissions(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1F6F54', 'objectif_mensuel' => '50 000 000'])
            ->assertSessionHas('succes');
        $this->assertSame(50_000_000, (int) $b->fresh()->objectif_mensuel);

        $awa = $this->vendeur($b, 'awa@test.gn');
        $this->put("/utilisateurs/{$awa->id}", ['prenom' => 'Awa', 'nom' => 'Camara', 'email' => 'awa@test.gn', 'role_id' => $awa->role_id, 'actif' => 1,
            'objectif_mensuel' => '8 000 000', 'commission_pct' => '1.5'])->assertSessionHasNoErrors();
        $awa->refresh();
        $this->assertSame(8_000_000, $awa->objectif_mensuel);
        $this->assertSame(1.5, $awa->commission_pct);
        $this->get("/utilisateurs/{$awa->id}/edit")->assertSee('Objectif et commission');

        // Sans gestion d'équipe dans la formule : champs absents, valeurs conservées
        $b->update(['derogations' => null]);
        $b->plan->update(['fonctions' => ['hors_ligne']]);
        $this->actingAs($admin->fresh())->get("/utilisateurs/{$awa->id}/edit")->assertDontSee('Objectif et commission');
        $this->put("/utilisateurs/{$awa->id}", ['prenom' => 'Awa', 'nom' => 'Camara', 'email' => 'awa@test.gn', 'role_id' => $awa->role_id, 'actif' => 1,
            'objectif_mensuel' => '1', 'commission_pct' => '40'])->assertSessionHasNoErrors();
        $this->assertSame(8_000_000, $awa->fresh()->objectif_mensuel);
    }
}
