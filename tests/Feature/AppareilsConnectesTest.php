<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\AlerteSecuriteCompte;
use App\Support\Appareils;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Sécurité des comptes : appareils connectés, déconnexion à distance, changement d'e-mail protégé et signalé. */
class AppareilsConnectesTest extends TestCase
{
    private const ANDROID = 'Mozilla/5.0 (Linux; Android 13; SM-A145F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Mobile Safari/537.36';

    private const WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36 Edg/120.0';

    private function ouvrirSession(User $u, string $id, string $agent, string $ip = '41.82.10.5'): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $u->id, 'ip_address' => $ip, 'user_agent' => $agent, 'payload' => '', 'last_activity' => time()]);
    }

    public function test_voir_et_deconnecter_ses_appareils(): void
    {
        config(['session.driver' => 'database']);
        [$b, $admin] = $this->creerBoutique();
        $this->ouvrirSession($admin, 'session-telephone-perdu-000000001', self::ANDROID);
        $this->ouvrirSession($admin, 'session-ordinateur-bureau-0000002', self::WINDOWS, '197.149.1.9');
        [, $autre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->ouvrirSession($autre, 'session-d-un-autre-compte-000003', self::WINDOWS);

        $page = $this->actingAs($admin)->get('/profil')->assertOk()->assertSee('Appareils connectés')
            ->assertSee('Chrome sur Android')->assertSee('Edge sur Windows')->assertSee('197.149.1.9')->assertSee('Déconnecter tous les autres');
        // L'identifiant de session ne figure jamais dans la page
        $page->assertDontSee('session-telephone-perdu', false);

        // Déconnecter le téléphone perdu, et lui seul
        $this->delete('/profil/appareils/'.Appareils::empreinte('session-telephone-perdu-000000001'))->assertSessionHas('succes');
        $this->assertDatabaseMissing('sessions', ['id' => 'session-telephone-perdu-000000001']);
        $this->assertDatabaseHas('sessions', ['id' => 'session-ordinateur-bureau-0000002']);
        // Impossible de viser l'appareil d'un autre compte
        $this->delete('/profil/appareils/'.Appareils::empreinte('session-d-un-autre-compte-000003'))->assertSessionHas('erreur');
        $this->assertDatabaseHas('sessions', ['id' => 'session-d-un-autre-compte-000003']);

        $this->delete('/profil/appareils')->assertSessionHas('succes');
        $this->assertSame(0, DB::table('sessions')->where('user_id', $admin->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $autre->id)->count());
    }

    public function test_l_administrateur_deconnecte_un_employe_partout(): void
    {
        config(['session.driver' => 'database']);
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $this->ouvrirSession($vendeur, 'session-vendeur-telephone-vole-01', self::ANDROID);

        $this->actingAs($admin)->get('/utilisateurs')->assertSee(route('utilisateurs.deconnecter', $vendeur));
        $this->post("/utilisateurs/{$vendeur->id}/deconnecter")->assertSessionHas('succes', fn ($m) => str_contains($m, 'Awa Sow est déconnecté'));
        $this->assertSame(0, DB::table('sessions')->where('user_id', $vendeur->id)->count());

        // Désactiver un compte ferme aussi ses sessions
        $this->ouvrirSession($vendeur, 'session-vendeur-revenu-000000002', self::ANDROID);
        $this->delete("/utilisateurs/{$vendeur->id}");
        $this->assertSame(0, DB::table('sessions')->where('user_id', $vendeur->id)->count());

        // Un vendeur ne peut pas déconnecter les autres ; un administrateur d'une autre boutique non plus
        [, $autreAdmin] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->actingAs($autreAdmin->fresh())->post("/utilisateurs/{$vendeur->id}/deconnecter")->assertStatus(404);
    }

    public function test_changer_d_email_demande_le_mot_de_passe_et_previent_l_ancienne_adresse(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();
        $base = ['prenom' => 'Admin', 'nom' => 'Test', 'email' => 'pirate@exemple.com'];

        $this->actingAs($admin)->put('/profil', $base)->assertSessionHasErrors('mot_de_passe_email');
        $this->put('/profil', $base + ['mot_de_passe_email' => 'mauvais'])->assertSessionHasErrors('mot_de_passe_email');
        $this->assertSame('admin@test.gn', $admin->fresh()->email);

        // Le nom seul se change sans mot de passe
        $this->put('/profil', ['prenom' => 'Mamadou', 'nom' => 'Test', 'email' => 'ADMIN@test.gn'])->assertSessionHasNoErrors();
        Notification::assertSentOnDemandTimes(AlerteSecuriteCompte::class, 0);

        $this->put('/profil', $base + ['mot_de_passe_email' => 'secret123'])->assertSessionHasNoErrors();
        $this->assertSame('pirate@exemple.com', $admin->fresh()->email);
        Notification::assertSentOnDemand(AlerteSecuriteCompte::class,
            fn ($n, $canaux, $notifiable) => $notifiable->routes['mail'] === 'admin@test.gn');

        // Changement de mot de passe : le titulaire est prévenu aussi
        $this->put('/profil/mot-de-passe', ['mot_de_passe_actuel' => 'secret123', 'password' => 'Nouveau2026', 'password_confirmation' => 'Nouveau2026']);
        Notification::assertSentOnDemand(AlerteSecuriteCompte::class,
            fn ($n, $canaux, $notifiable) => $notifiable->routes['mail'] === 'pirate@exemple.com');
    }
}
