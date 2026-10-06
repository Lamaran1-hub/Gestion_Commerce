<?php

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\ClotureCaisse;
use App\Models\Role;
use App\Models\User;
use App\Notifications\RappelLicence;
use App\Services\VenteService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CaisseEtRappelsTest extends TestCase
{
    // ---------- Relances d'échéance ----------

    public function test_relances_aux_jalons_une_seule_fois(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();
        $b->update(['abonnement_expire_le' => now()->addDays(7)->toDateString()]);
        [$autre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $autre->update(['abonnement_expire_le' => now()->addDays(10)->toDateString()]); // pas un jalon

        $this->artisan('licences:rappels')->expectsOutputToContain('1 boutique(s)')->assertSuccessful();
        Notification::assertSentTo($admin, RappelLicence::class, fn ($n) => str_contains($n->titre(), '7 jours'));
        $this->assertSame(1, Annonce::where('boutique_id', $b->id)->count());

        // Relancer la tâche le même jour n'envoie rien de plus
        $this->artisan('licences:rappels')->expectsOutputToContain('0 boutique(s)');
        $this->assertSame(1, Annonce::where('boutique_id', $b->id)->count());

        // Le lendemain de l'échéance : rappel « expirée », en annonce importante
        $b->update(['abonnement_expire_le' => now()->subDay()->toDateString()]);
        $this->artisan('licences:rappels');
        $this->assertSame('important', Annonce::where('boutique_id', $b->id)->latest('id')->value('type'));
        $this->assertStringContainsString('a expiré', Annonce::where('boutique_id', $b->id)->latest('id')->value('titre'));
    }

    // ---------- Clôture de caisse ----------

    private function vendeur($b): User
    {
        return User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Camara', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'role_id' => Role::withoutGlobalScope('boutique')->where('boutique_id', $b->id)->where('nom', 'Vendeur')->value('id')]);
    }

    public function test_bilan_et_cloture_avec_ecart_justifie(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $vendeur = $this->vendeur($b);
        $this->dans($b, function () use ($p) {
            app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']);
            app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'orange_money']);
        }, $vendeur);

        $this->actingAs($vendeur)->get('/caisse/cloture')->assertOk()->assertSee('300 000 GNF')->assertSee('Orange Money');

        // Écart sans motif : refusé
        $this->post('/caisse/cloture', ['especes_comptees' => '295 000'])->assertSessionHas('erreur');
        $this->assertSame(0, ClotureCaisse::withoutGlobalScope('boutique')->count());

        $this->post('/caisse/cloture', ['especes_comptees' => '295 000', 'motif' => 'Erreur de rendu de monnaie'])->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->first();
        $this->assertSame(300_000, $c->especes_theoriques);
        $this->assertSame(-5_000, $c->ecart);
        $this->assertSame(['especes' => 300_000, 'orange_money' => 300_000], $c->encaissements);
        $this->get("/clotures/{$c->id}")->assertOk()->assertSee('Manque de 5 000 GNF');
    }

    public function test_caisse_cloturee_bloque_ventes_et_encaissements_jusqu_a_reouverture(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $vendeur = $this->vendeur($b);

        $this->actingAs($vendeur)->post('/caisse/cloture', ['especes_comptees' => '0'])->assertRedirect();
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'caisse est clôturée'));
        $this->assertEquals(20, $p->fresh()->stock);
        $this->get('/caisse')->assertSee('clôturée pour aujourd');

    }

    public function test_seul_l_administrateur_rouvre_la_caisse_du_jour(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $vendeur = $this->vendeur($b);
        $this->actingAs($vendeur)->post('/caisse/cloture', ['especes_comptees' => '0']);
        $c = ClotureCaisse::withoutGlobalScope('boutique')->first();

        $this->delete("/clotures/{$c->id}")->assertSessionHas('erreur', fn ($m) => str_contains($m, "Seul l'administrateur"));
        $this->actingAs($admin)->delete("/clotures/{$c->id}")->assertSessionHas('succes');
        $this->actingAs($vendeur)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes'])
            ->assertSessionHasNoErrors()->assertSessionMissing('erreur');
        $this->assertEquals(19, $p->fresh()->stock);
    }

    public function test_les_remboursements_du_jour_sont_deduits_et_les_clotures_isolees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->actingAs($admin)->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie']);

        $bilan = $this->dans($b, fn () => app(\App\Services\CaisseService::class)->bilan($admin, now()));
        $this->assertSame(0, $bilan['especes_theoriques'], '300 000 encaissés − 300 000 remboursés');

        // Une autre boutique ne voit pas cette clôture
        $this->post('/caisse/cloture', ['especes_comptees' => '0']);
        $c = ClotureCaisse::withoutGlobalScope('boutique')->first();
        [$b2, $admin2] = $this->creerBoutique('B2', 'b2@test.gn');
        $this->actingAs($admin2)->get("/clotures/{$c->id}")->assertNotFound();
    }
}
