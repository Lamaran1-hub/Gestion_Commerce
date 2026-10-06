<?php

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\EmailEnvoye;
use App\Models\User;
use App\Notifications\Bienvenue;
use App\Notifications\LicenceActivee;
use App\Notifications\Nouveaute;
use App\Notifications\StatutBoutique;
use App\Support\Plateforme;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** E-mails : bienvenue, paiement avec reçu, suspension, nouveautés (désabonnement), configuration SMTP, journal. */
class EmailsTest extends TestCase
{
    private function proprietaire(): User
    {
        return User::create(['prenom' => 'Éditeur', 'nom' => 'Logiciel', 'email' => 'editeur@test.gn', 'password' => 'secret123', 'est_super_admin' => true]);
    }

    public function test_bienvenue_a_la_creation_et_journal(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();
        Notification::assertSentTo($admin, Bienvenue::class);
        $trace = EmailEnvoye::where('type', 'bienvenue')->first();
        $this->assertSame('admin@test.gn', $trace->destinataire);
        $this->assertSame('simule', $trace->statut, 'envoi réel non configuré : e-mail seulement simulé');
        $this->assertStringContainsString('Bienvenue', $trace->sujet);
    }

    public function test_paiement_au_guichet_et_suspension_previennent_le_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        Notification::fake();
        $moi = $this->proprietaire();

        $this->actingAs($moi)->post("/admin/boutiques/{$b->id}/paiements", ['duree' => '12', 'montant' => '1 800 000', 'mode' => 'orange_money',
            'paye_le' => now()->toDateString()])->assertSessionHas('succes', fn ($m) => str_contains($m, 'Confirmation envoyée par e-mail'));
        Notification::assertSentTo($admin, LicenceActivee::class, function ($n, $canaux) use ($admin) {
            $mail = $n->toMail($admin);

            return in_array('database', $canaux, true) || str_contains($mail->subject, 'Paiement reçu') && count($mail->rawAttachments) === 1;
        });

        $this->post("/admin/boutiques/{$b->id}/statut");
        Notification::assertSentTo($admin, StatutBoutique::class, fn ($n) => str_contains($n->toMail($admin)->subject, 'suspendue'));
    }

    public function test_nouveaute_envoyee_une_fois_et_desabonnement(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$b2, $admin2] = $this->creerBoutique('Autre', 'autre@test.gn');
        $admin2->update(['recevoir_nouveautes' => false]);
        Notification::fake();
        $moi = $this->proprietaire();

        $this->actingAs($moi)->post('/admin/annonces', ['titre' => 'Nouvelle version', 'contenu' => "Ligne 1\n\nLigne 2", 'type' => 'info',
            'publier' => 1, 'envoyer_email' => 1])->assertSessionHas('succes', fn ($m) => str_contains($m, '1 administrateur'));
        Notification::assertSentTo($admin, Nouveaute::class);
        Notification::assertNotSentTo($admin2, Nouveaute::class);
        $annonce = Annonce::first();
        $this->assertNotNull($annonce->email_envoye_le);
        // Pas de second envoi à la modification
        $this->put("/admin/annonces/{$annonce->id}", ['titre' => 'Nouvelle version', 'contenu' => 'x', 'type' => 'info', 'publier' => 1, 'envoyer_email' => 1]);
        Notification::assertSentToTimes($admin, Nouveaute::class, 1);

        // Lien de désabonnement signé, sans connexion ; un lien trafiqué est refusé
        auth()->logout();
        $this->get(URL::signedRoute('emails.desabonner', ['user' => $admin->id]))->assertOk()->assertSee('désabonné');
        $this->assertFalse($admin->fresh()->recevoir_nouveautes);
        $this->get("/emails/desabonner/{$admin2->id}")->assertForbidden();
        // Réabonnement depuis le profil
        $this->actingAs($admin->fresh())->put('/profil', ['prenom' => 'Admin', 'nom' => 'X', 'email' => 'admin@test.gn', 'recevoir_nouveautes' => 1]);
        $this->assertTrue($admin->fresh()->recevoir_nouveautes);
    }

    public function test_gabarit_professionnel_aux_couleurs_de_l_editeur(): void
    {
        Plateforme::enregistrer(['societe' => 'GN Solutions SARL', 'telephone' => '+224 620 00 00 00', 'adresse' => 'Kaloum, Conakry']);
        [$b, $admin] = $this->creerBoutique();
        $annonce = Annonce::create(['titre' => 'Nouveauté', 'contenu' => 'Texte', 'type' => 'info', 'publiee_le' => now()]);
        foreach ([new Bienvenue($b), new StatutBoutique($b), new Nouveaute($annonce), new \App\Notifications\EmailTest] as $n) {
            $html = (string) $n->toMail($admin)->render();
            $this->assertStringContainsString('GN Solutions SARL', $html, class_basename($n));
            $this->assertStringContainsString('Kaloum, Conakry', $html);
            $this->assertStringContainsString('Cordialement', $html);
            $this->assertStringNotContainsString('Laravel', $html);
            $this->assertStringNotContainsString('Regards', $html);
            $this->assertStringContainsString('#1F6F54', $html, 'couleur de la marque');
        }
        $this->assertStringContainsString('désabonner', (string) (new Nouveaute($annonce))->toMail($admin)->render());
    }

    public function test_configuration_smtp_et_email_de_test(): void
    {
        $moi = $this->proprietaire();
        $this->actingAs($moi)->get('/admin/emails')->assertOk()->assertSee('Envoi simulé');

        $this->post('/admin/emails/configuration', ['emails_actifs' => 1, 'smtp_hote' => 'smtp.exemple.gn', 'smtp_port' => 587, 'smtp_chiffrement' => 'tls',
            'smtp_utilisateur' => 'contact@exemple.gn', 'smtp_mot_de_passe' => 'MotDePasse!', 'expediteur_email' => 'contact@exemple.gn', 'expediteur_nom' => 'Mon Logiciel'])
            ->assertSessionHas('succes');
        $this->assertNotSame('MotDePasse!', Plateforme::get('smtp_mot_de_passe'), 'mot de passe chiffré en base');
        $this->assertSame('MotDePasse!', Crypt::decryptString(Plateforme::get('smtp_mot_de_passe')));
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp.exemple.gn', config('mail.mailers.smtp.host'));
        $this->assertSame('contact@exemple.gn', config('mail.from.address'));
        // Mot de passe laissé vide : inchangé
        $this->post('/admin/emails/configuration', ['emails_actifs' => 1, 'smtp_hote' => 'smtp.exemple.gn', 'smtp_port' => 587, 'smtp_chiffrement' => 'tls',
            'expediteur_email' => 'contact@exemple.gn']);
        $this->assertSame('MotDePasse!', Crypt::decryptString(Plateforme::get('smtp_mot_de_passe')));

        // Désactivation : retour à l'envoi simulé, et l'e-mail de test est tracé
        $this->post('/admin/emails/configuration', ['smtp_chiffrement' => 'tls']);
        $this->assertNotSame('smtp', config('mail.default'));
        $this->post('/admin/emails/test', ['email_test' => 'moi@exemple.gn'])->assertSessionHas('succes', fn ($m) => str_contains($m, 'simulé'));
        $this->assertTrue(EmailEnvoye::where('type', 'test')->where('destinataire', 'moi@exemple.gn')->exists());
        $this->get('/admin/emails?type=test')->assertOk()->assertSee('moi@exemple.gn');
    }
}
