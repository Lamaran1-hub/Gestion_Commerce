<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Paiement\DjomyClient;
use App\Support\VerificationSecurite;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Durcissement : en-têtes HTTP, blocage des essais de mot de passe, secrets chiffrés, contrôle de la configuration. */
class SecuriteTest extends TestCase
{
    public function test_en_tetes_de_securite_sur_toutes_les_pages(): void
    {
        [$b, $admin] = $this->creerBoutique();
        foreach (['/connexion', '/'] as $page) {
            $r = $this->get($page);
            $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeaderMissing('X-Powered-By');
            $csp = $r->headers->get('Content-Security-Policy');
            $this->assertStringContainsString("default-src 'self'", $csp);
            $this->assertStringContainsString("frame-ancestors 'self'", $csp);
            $this->assertStringContainsString("object-src 'none'", $csp);
            $this->assertStringContainsString('camera=(self)', $r->headers->get('Permissions-Policy'));
        }
        // Pages connectées et réponses JSON aussi ; pas de HSTS hors HTTPS
        $this->actingAs($admin)->get('/caisse')->assertOk()->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeaderMissing('Strict-Transport-Security');
        $this->postJson('/webhooks/djomy', [])->assertStatus(401)->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_compte_bloque_apres_cinq_mots_de_passe_faux(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->creerBoutique('Autre', 'autre@test.gn');
        for ($i = 0; $i < 5; $i++) {
            $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'mauvais'.$i])->assertSessionHasErrors('email');
        }
        // Même le bon mot de passe est refusé pendant le blocage
        $this->post('/connexion', ['email' => 'ADMIN@test.gn', 'password' => 'secret123'])
            ->assertSessionHasErrors(['email' => 'Trop de mots de passe incorrects. Réessayez dans 15 minute(s) ou utilisez « Mot de passe oublié ».']);
        $this->assertGuest();
        // Les autres comptes ne sont pas touchés
        $this->post('/connexion', ['email' => 'autre@test.gn', 'password' => 'secret123'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_connexion_reussie_remet_le_compteur_a_zero(): void
    {
        $this->creerBoutique();
        for ($i = 0; $i < 4; $i++) {
            $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'faux']);
        }
        $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'secret123'])->assertRedirect();
        $this->post('/deconnexion');
        for ($i = 0; $i < 4; $i++) {
            $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'faux']);
        }
        $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'secret123'])->assertSessionHasNoErrors();
        $this->assertAuthenticated();
    }

    public function test_mot_de_passe_change_par_l_admin_deconnecte_les_appareils_du_vendeur(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Kadi', 'nom' => 'Sow', 'email' => 'kadi@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => \App\Models\Role::where('nom', 'Vendeur')->value('id')]));
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'telephone-perdu', 'user_id' => $vendeur->id, 'payload' => '', 'last_activity' => time()]);
        \Illuminate\Support\Facades\DB::table('sessions')->insert(['id' => 'session-admin', 'user_id' => $admin->id, 'payload' => '', 'last_activity' => time()]);

        $champs = ['prenom' => 'Kadi', 'nom' => 'Sow', 'email' => 'kadi@test.gn', 'role_id' => $vendeur->role_id, 'actif' => 1];
        // Sans nouveau mot de passe : rien ne change
        $this->actingAs($admin)->put("/utilisateurs/{$vendeur->id}", $champs)->assertSessionHas('succes');
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'telephone-perdu')->exists());

        $this->put("/utilisateurs/{$vendeur->id}", $champs + ['password' => 'Nouveau2026', 'password_confirmation' => 'Nouveau2026'])->assertSessionHas('succes');
        $this->assertFalse(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'telephone-perdu')->exists(), 'le téléphone perdu est déconnecté');
        $this->assertTrue(\Illuminate\Support\Facades\DB::table('sessions')->where('id', 'session-admin')->exists(), 'la session de l\'administrateur reste ouverte');
    }

    public function test_formulaires_publics_refusent_les_robots(): void
    {
        config(['gestion.inscription_ouverte' => true]);
        $inscription = ['boutique_nom' => 'Spam Shop', 'boutique_telephone' => '620000000', 'prenom' => 'Bot', 'nom' => 'Bot',
            'email' => 'bot@spam.test', 'password' => 'Spam12345', 'password_confirmation' => 'Spam12345'];

        $this->get('/creer-ma-boutique')->assertOk()->assertSee('name="site_web"', false)->assertSee('name="_affiche"', false);
        // Robot qui remplit le champ leurre, robot trop rapide, robot qui n'envoie pas l'heure d'affichage
        $this->post('/creer-ma-boutique', $this->humain() + ['site_web' => 'http://spam.test'] + $inscription)->assertSessionHasErrors('formulaire');
        $this->post('/creer-ma-boutique', ['_affiche' => \Illuminate\Support\Facades\Crypt::encryptString((string) time())] + $inscription)->assertSessionHasErrors('formulaire');
        $this->post('/creer-ma-boutique', $inscription)->assertSessionHasErrors('formulaire');
        $this->post('/creer-ma-boutique', ['_affiche' => 'valeur-trafiquée'] + $inscription)->assertSessionHasErrors('formulaire');
        $this->assertFalse(User::where('email', 'bot@spam.test')->exists());

        // Un humain passe
        $this->post('/creer-ma-boutique', $this->humain() + $inscription)->assertSessionHasNoErrors();
        $this->assertTrue(User::where('email', 'bot@spam.test')->exists());
    }

    public function test_image_svg_refusee_et_disque_prive_jamais_servi(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($admin)->post('/produits', ['designation' => 'Test', 'unite' => 'pièce', 'prix_achat' => '100', 'prix_vente' => '200', 'image' => $svg])
            ->assertSessionHasErrors('image');
        $this->assertFalse(collect(Route::getRoutes())->contains(fn ($r) => $r->uri() === 'storage/{path}'), 'pas de route de lecture du disque privé');
        $this->assertFileExists(storage_path('app/public/.htaccess'));
    }

    public function test_jeton_djomy_chiffre_dans_le_cache(): void
    {
        config(['services.djomy' => ['actif' => true, 'mode' => 'sandbox', 'numero_marchand' => '224627406834', 'url' => 'https://sandbox-api.djomy.africa',
            'client_id' => 'cid', 'client_secret' => 'secret', 'pays' => 'GN', 'moyens' => ['OM'], 'delai' => 5]]);
        Http::fake(['*/v1/auth' => Http::response(['success' => true, 'data' => ['accessToken' => 'jeton-tres-secret']])]);
        $jeton = (fn () => $this->jeton())->call(app(DjomyClient::class));

        $this->assertSame('jeton-tres-secret', $jeton);
        $enCache = Cache::get('djomy.jeton.sandbox');
        $this->assertNotSame('jeton-tres-secret', $enCache);
        $this->assertStringNotContainsString('jeton-tres-secret', (string) $enCache);
        // Ancien jeton resté en clair : relu proprement (nouveau jeton demandé)
        Cache::put('djomy.jeton.sandbox', 'ancien-jeton-en-clair', 600);
        $this->assertSame('jeton-tres-secret', (fn () => $this->jeton())->call(app(DjomyClient::class)));
    }

    public function test_controle_de_la_configuration_en_production(): void
    {
        User::create(['prenom' => 'P', 'nom' => 'Proprio', 'email' => 'moi@editeur.gn', 'password' => 'ChangezMoi2026!', 'est_super_admin' => true]);
        $this->app['env'] = 'production';
        config(['app.debug' => true, 'app.url' => 'http://exemple.gn', 'session.secure' => null]);

        $points = collect(VerificationSecurite::controles())->where('niveau', 'critique')->pluck('titre');
        $this->assertTrue($points->contains('Mode débogage désactivé en ligne (APP_DEBUG=false)'));
        $this->assertTrue($points->contains('Adresse en HTTPS (APP_URL)'));
        $this->assertTrue($points->contains('Cookie de session sécurisé'));
        $this->assertTrue($points->contains('Comptes avec un mot de passe connu (1)'));
        $this->artisan('securite:verifier')->expectsOutputToContain('point(s) critique(s)')->assertFailed();

        // Le propriétaire voit l'alerte sur son tableau de bord
        $this->actingAs(User::where('email', 'moi@editeur.gn')->first())->get('/admin')
            ->assertSee('Sécurité de l\'installation', false)->assertSee('moi@editeur.gn')->assertSee('APP_DEBUG');

        // Configuration corrigée : plus aucun point critique
        User::where('email', 'moi@editeur.gn')->first()->update(['password' => 'UnVraiMotDePasse2026']);
        config(['app.debug' => false, 'app.url' => 'https://exemple.gn', 'session.secure' => true]);
        $this->assertEmpty(collect(VerificationSecurite::controles())->where('niveau', 'critique')->all());
        $this->artisan('securite:verifier')->assertSuccessful();
    }
}
