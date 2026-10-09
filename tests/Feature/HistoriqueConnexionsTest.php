<?php

namespace Tests\Feature;

use App\Models\Connexion;
use App\Notifications\AlerteSecuriteCompte;
use App\Services\SecuriteConnexion;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Historique des connexions : nouvel appareil signalé, essais de mots de passe signalés et visibles. */
class HistoriqueConnexionsTest extends TestCase
{
    private function connecter(array $temoin = [])
    {
        $this->defaultCookies = $temoin;   // chaque connexion part de cet appareil seulement
        $r = $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'secret123']);
        $this->post('/deconnexion');

        return $r;
    }

    public function test_un_nouvel_appareil_previent_le_titulaire(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();

        // Toute première connexion : pas d'alerte, l'appareil reçoit son témoin
        $r = $this->connecter();
        $r->assertCookie(SecuriteConnexion::TEMOIN);
        $temoin = $r->getCookie(SecuriteConnexion::TEMOIN)->getValue();
        Notification::assertNotSentTo($admin, AlerteSecuriteCompte::class);

        // Même appareil : rien à signaler
        $this->connecter([SecuriteConnexion::TEMOIN => $temoin]);
        Notification::assertNotSentTo($admin, AlerteSecuriteCompte::class);

        // Autre appareil (sans le témoin) : le titulaire est prévenu
        $this->connecter();
        Notification::assertSentToTimes($admin, AlerteSecuriteCompte::class, 1);
        $this->assertSame([true, true, true], Connexion::where('user_id', $admin->id)->orderBy('id')->pluck('reussie')->all());
        $this->assertSame([false, false, true], Connexion::where('user_id', $admin->id)->orderBy('id')->pluck('nouvel_appareil')->all());
        // Le témoin lui-même n'est jamais conservé, seulement son empreinte
        $this->assertFalse(Connexion::where('jeton_appareil', $temoin)->exists());

        $this->actingAs($admin)->get('/profil')->assertSee('Dernières connexions')->assertSee('nouvel appareil');
    }

    public function test_les_mots_de_passe_faux_sont_notes_et_le_blocage_signale(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();

        for ($i = 0; $i < 5; $i++) {
            $this->post('/connexion', ['email' => 'Admin@test.gn', 'password' => 'mauvais'.$i]);
        }
        $this->assertSame(5, Connexion::where('user_id', $admin->id)->where('reussie', false)->count());
        Notification::assertSentToTimes($admin, AlerteSecuriteCompte::class, 1);   // une seule fois, au blocage

        // Adresse inconnue : notée, sans compte ni alerte
        $this->post('/connexion', ['email' => 'inconnu@test.gn', 'password' => 'x']);
        $this->assertDatabaseHas('connexions', ['email' => 'inconnu@test.gn', 'user_id' => null, 'reussie' => false]);

        $this->actingAs($admin)->get('/profil')->assertSee('mot de passe incorrect');
        $this->get('/utilisateurs')->assertSee('5 mot(s) de passe faux (24 h)');
    }
}
