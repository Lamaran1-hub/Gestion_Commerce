<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResumeDuJour;
use App\Services\Sauvegarde;
use App\Services\VenteService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Notifications consultables, résumé du matin, sauvegardes. */
class AutomatisationsTest extends TestCase
{
    public function test_les_notifications_se_lisent_et_la_cloche_redescend(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['abonnement_expire_le' => now()->addDays(3)->toDateString()]);
        $this->artisan('licences:rappels');
        $this->assertSame(1, $admin->unreadNotifications()->count());

        $this->actingAs($admin)->get('/notifications')->assertOk()->assertSee('Votre licence expire dans 3 jours');
        $this->assertSame(0, $admin->fresh()->unreadNotifications()->count());

        $id = $admin->notifications()->first()->id;
        $this->get("/notifications/{$id}")->assertRedirect(route('abonnement'));

        // Pas d'accès aux notifications d'un autre
        [$b2, $autre] = $this->creerBoutique('B2', 'b2@test.gn');
        $this->actingAs($autre)->get("/notifications/{$id}")->assertNotFound();
    }

    public function test_resume_du_matin_envoye_seulement_s_il_y_a_quelque_chose(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, ['seuil_alerte' => 5], 20);

        $this->artisan('boutiques:resume')->expectsOutputToContain('0 résumé(s)');

        // Hier : une vente sans clôture de caisse
        $this->travel(-1)->days();
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->travelBack();

        $this->artisan('boutiques:resume')->expectsOutputToContain('1 résumé(s)');
        Notification::assertSentTo($admin, ResumeDuJour::class, function ($n) use ($admin) {
            $m = $n->toArray($admin)['message'];

            return str_contains($m, 'Ventes : 1 pour 300 000 GNF') && str_contains($m, 'Caisse non clôturée');
        });

        // Désactivable par la boutique
        $b->update(['resume_quotidien' => false]);
        $this->artisan('boutiques:resume')->expectsOutputToContain('0 résumé(s)');
    }

    public function test_sauvegarde_creee_telechargeable_et_protegee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $moi = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);
        $avant = collect(app(Sauvegarde::class)->lister())->pluck('nom');

        $this->actingAs($moi)->post('/admin/sauvegardes')->assertSessionHas('succes');
        $nom = collect(app(Sauvegarde::class)->lister())->pluck('nom')->diff($avant)->first();
        $this->assertNotNull($nom);

        $contenu = gzdecode(file_get_contents(app(Sauvegarde::class)->chemin($nom)));
        $this->assertStringContainsString('CREATE TABLE', $contenu);
        $this->assertStringContainsString($b->nom, $contenu);
        $this->assertStringNotContainsString('INSERT INTO "sessions"', $contenu);

        $this->get("/admin/sauvegardes/{$nom}")->assertOk()->assertDownload($nom);
        $this->get('/admin/sauvegardes/..%2F..%2F.env')->assertNotFound();

        // Réservé au propriétaire
        $this->actingAs($admin)->get('/admin/sauvegardes')->assertForbidden();

        $this->actingAs($moi)->delete("/admin/sauvegardes/{$nom}")->assertSessionHas('succes');
        $this->assertNotContains($nom, collect(app(Sauvegarde::class)->lister())->pluck('nom'));
    }
}
