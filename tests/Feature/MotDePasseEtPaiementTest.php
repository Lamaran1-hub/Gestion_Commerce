<?php

namespace Tests\Feature;

use App\Models\CommandeLicence;
use App\Models\PaiementLicence;
use App\Models\Plan;
use App\Models\User;
use App\Notifications\LicenceActivee;
use App\Notifications\PaiementLicenceRecu;
use App\Notifications\ReinitialisationMotDePasse;
use App\Support\Plateforme;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class MotDePasseEtPaiementTest extends TestCase
{
    // ---------- Mot de passe oublié ----------

    public function test_lien_de_reinitialisation_puis_nouveau_mot_de_passe(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();

        $this->post('/mot-de-passe-oublie', ['email' => $admin->email])->assertSessionHas('succes');
        $jeton = null;
        Notification::assertSentTo($admin, ReinitialisationMotDePasse::class, function ($n) use (&$jeton, $admin) {
            $jeton = (fn () => $this->jeton)->call($n);

            return str_contains($n->toMail($admin)->actionUrl, '/reinitialiser-mot-de-passe/');
        });

        $this->get("/reinitialiser-mot-de-passe/{$jeton}?email={$admin->email}")->assertOk();
        $this->post('/reinitialiser-mot-de-passe', ['token' => $jeton, 'email' => $admin->email,
            'password' => 'Nouveau2026', 'password_confirmation' => 'Nouveau2026'])->assertRedirect('/connexion')->assertSessionHas('succes');

        $this->post('/connexion', ['email' => $admin->email, 'password' => 'Nouveau2026'])->assertRedirect();
        $this->assertAuthenticated();

        // Lien à usage unique
        $this->post('/deconnexion');
        $this->post('/reinitialiser-mot-de-passe', ['token' => $jeton, 'email' => $admin->email,
            'password' => 'Autre2026x', 'password_confirmation' => 'Autre2026x'])->assertSessionHasErrors('email');
    }

    public function test_meme_reponse_si_le_compte_n_existe_pas_ou_est_suspendu(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();
        $admin->update(['actif' => false]);

        $this->post('/mot-de-passe-oublie', ['email' => 'inconnu@test.gn'])->assertSessionHas('succes');
        $this->post('/mot-de-passe-oublie', ['email' => $admin->email])->assertSessionHas('succes');
        // Aucun lien de réinitialisation (seul l'e-mail de bienvenue de la création de la boutique est parti)
        Notification::assertNotSentTo($admin, \App\Notifications\ReinitialisationMotDePasse::class);
        Notification::assertSentTimes(\App\Notifications\ReinitialisationMotDePasse::class, 0);
    }

    public function test_rappel_du_mot_de_passe_provisoire_explicite_et_reportable(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $admin->update(['doit_changer_mot_de_passe' => true]);
        $this->post('/connexion', ['email' => $admin->email, 'password' => 'secret123']);

        $this->get('/produits')->assertSee('Mon profil')->assertSee('Changer de mot de passe')->assertSee('Me rappeler plus tard');
        $this->postJson('/profil/rappel-mot-de-passe')->assertNoContent();
        $this->get('/produits')->assertDontSee('Me rappeler plus tard');

        // Il revient à la connexion suivante
        $this->post('/deconnexion');
        $this->post('/connexion', ['email' => $admin->email, 'password' => 'secret123']);
        $this->get('/produits')->assertSee('Me rappeler plus tard');
    }

    // ---------- Paiement de licence en ligne (Djomy) ----------

    private function configurerDjomy(): void
    {
        config(['services.djomy' => [
            'actif' => true, 'mode' => 'sandbox', 'numero_marchand' => '224627406834',
            'url' => 'https://sandbox-api.djomy.africa', 'client_id' => 'cid', 'client_secret' => 'secret',
            'pays' => 'GN', 'moyens' => ['OM', 'MOMO', 'CARD', 'PAYCARD', 'KULU', 'SOUTRA_MONEY'], 'delai' => 5,
        ]]);
    }

    private function fauxDjomy(string $statut = 'SUCCESS', int $montantRecu = 3_600_000): void
    {
        Http::fake([
            '*/v1/auth' => Http::response(['success' => true, 'data' => ['accessToken' => 'jeton-test']]),
            '*/v1/payments/gateway' => Http::response(['success' => true, 'data' => [
                'transactionId' => 'TX-123', 'redirectUrl' => 'https://pay.djomy.africa/p/TX-123', 'status' => 'CREATED']]),
            '*/v1/payments/TX-123/status' => Http::response(['success' => true, 'data' => [
                'transactionId' => 'TX-123', 'status' => $statut, 'receivedAmount' => $montantRecu, 'paymentMethod' => 'KULU']]),
        ]);
    }

    public function test_achat_en_ligne_active_la_licence_et_notifie_le_proprietaire(): void
    {
        Notification::fake();
        $this->configurerDjomy();
        $this->fauxDjomy();
        [$b, $admin] = $this->creerBoutique();
        $b->update(['statut' => 'essai']);
        $plan = Plan::where('nom', 'Commerce')->first(); // 300 000 / mois
        $proprietaire = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);

        $this->actingAs($admin)->get('/abonnement')->assertSee('Payer ma licence en ligne');
        $this->post('/licence/commander', ['plan_id' => $plan->id, 'mois' => 12, 'numero_payeur' => '622 00 00 00',
            'montant' => 1]) // un montant envoyé par le navigateur est ignoré
            ->assertRedirect('https://pay.djomy.africa/p/TX-123');

        $commande = CommandeLicence::first();
        $this->assertSame(3_600_000, $commande->montant);
        $this->assertSame('224622000000', $commande->numero_payeur);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/payments/gateway') && $r['amount'] === 3_600_000
            && $r['merchantPaymentReference'] === $commande->reference && $r->hasHeader('X-API-KEY', 'cid:'.hash_hmac('sha256', 'cid', 'secret')));

        // Retour navigateur : le statut est relu chez Djomy avant d'activer
        $this->get("/licence/retour/{$commande->reference}")->assertRedirect()->assertSessionHas('succes');
        $commande->refresh();
        $this->assertSame('payee', $commande->statut);
        $this->assertTrue($b->fresh()->licencePayee());
        $this->assertSame('djomy_test', PaiementLicence::first()->mode, 'mode sandbox : paiement marqué TEST');
        Notification::assertSentTo($proprietaire, PaiementLicenceRecu::class);
        Notification::assertSentTo($admin, LicenceActivee::class);

        // Rejouer le retour ou le webhook ne prolonge pas une deuxième fois
        $this->get("/licence/retour/{$commande->reference}");
        $this->assertSame(1, PaiementLicence::count());
    }

    public function test_webhook_signe_et_idempotent(): void
    {
        Notification::fake();
        $this->configurerDjomy();
        $this->fauxDjomy();
        [$b, $admin] = $this->creerBoutique();
        $commande = CommandeLicence::create(['boutique_id' => $b->id, 'plan_id' => Plan::where('nom', 'Commerce')->value('id'), 'mois' => 12,
            'montant' => 3_600_000, 'transaction_id' => 'TX-123']);
        $corps = json_encode(['eventType' => 'payment.success', 'eventId' => 'EVT-1',
            'data' => ['payment' => ['transactionId' => 'TX-123', 'merchantPaymentReference' => $commande->reference, 'status' => 'SUCCESS']]]);
        $envoyer = fn (string $signature) => $this->call('POST', '/webhooks/djomy', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => $signature], $corps);

        $envoyer('v1:mauvaise')->assertStatus(401);
        $this->assertSame('en_attente', $commande->fresh()->statut);

        $envoyer('v1:'.hash_hmac('sha256', $corps, 'secret'))->assertOk();
        $this->assertSame('payee', $commande->fresh()->statut);

        $envoyer('v1:'.hash_hmac('sha256', $corps, 'secret'))->assertOk()->assertJson(['message' => 'Déjà traité']);
        $this->assertSame(1, PaiementLicence::count());
    }

    public function test_montant_recu_insuffisant_met_en_anomalie(): void
    {
        Notification::fake();
        $this->configurerDjomy();
        $this->fauxDjomy('SUCCESS', 100);
        [$b, $admin] = $this->creerBoutique();
        $b->update(['statut' => 'essai']);
        $commande = CommandeLicence::create(['boutique_id' => $b->id, 'plan_id' => Plan::where('nom', 'Commerce')->value('id'), 'mois' => 12,
            'montant' => 3_600_000, 'transaction_id' => 'TX-123']);

        app(\App\Services\Paiement\LicenceEnLigneService::class)->synchroniser($commande);
        $this->assertSame('anomalie', $commande->fresh()->statut);
        $this->assertFalse($b->fresh()->licencePayee());
        $this->assertSame(0, PaiementLicence::count());
    }

    public function test_activation_manuelle_si_le_proprietaire_le_choisit(): void
    {
        Notification::fake();
        $this->configurerDjomy();
        $this->fauxDjomy();
        Plateforme::enregistrer(['activation_auto' => '0']);
        [$b] = $this->creerBoutique();
        $b->update(['statut' => 'essai']);
        $proprietaire = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);
        $commande = CommandeLicence::create(['boutique_id' => $b->id, 'plan_id' => Plan::where('nom', 'Commerce')->value('id'), 'mois' => 12,
            'montant' => 3_600_000, 'transaction_id' => 'TX-123']);

        app(\App\Services\Paiement\LicenceEnLigneService::class)->synchroniser($commande);
        $this->assertSame('a_valider', $commande->fresh()->statut);
        $this->assertFalse($b->fresh()->licencePayee());
        Notification::assertSentTo($proprietaire, PaiementLicenceRecu::class);

        $this->actingAs($proprietaire)->get('/admin/paiements-en-ligne')->assertOk()->assertSee($commande->reference);
        $this->post("/admin/paiements-en-ligne/{$commande->id}/activer")->assertSessionHas('succes');
        $this->assertTrue($b->fresh()->licencePayee());
    }

    public function test_paiement_echoue_et_commande_abandonnee_expiree(): void
    {
        $this->configurerDjomy();
        $this->fauxDjomy('FAILED');
        [$b] = $this->creerBoutique();
        $plan = Plan::where('nom', 'Commerce')->value('id');
        $echouee = CommandeLicence::create(['boutique_id' => $b->id, 'plan_id' => $plan, 'mois' => 1, 'montant' => 300_000, 'transaction_id' => 'TX-123']);
        $echouee->forceFill(['created_at' => now()->subMinutes(5)])->save(); // la tâche laisse 2 min au webhook
        $abandonnee = CommandeLicence::create(['boutique_id' => $b->id, 'plan_id' => $plan, 'mois' => 1, 'montant' => 300_000]);
        $abandonnee->forceFill(['created_at' => now()->subDays(2)])->save();

        $this->artisan('licences:verifier-paiements')->assertSuccessful();
        $this->assertSame('echouee', $echouee->fresh()->statut);
        $this->assertSame('expiree', $abandonnee->fresh()->statut);
    }

    public function test_regles_de_la_commande(): void
    {
        $this->configurerDjomy();
        $this->fauxDjomy();
        [$b, $admin] = $this->creerBoutique();
        $plan = Plan::where('nom', 'Commerce')->value('id');

        $this->actingAs($admin)->post('/licence/commander', ['plan_id' => $plan, 'mois' => 5, 'numero_payeur' => '622000000'])->assertSessionHasErrors('mois');
        $this->post('/licence/commander', ['plan_id' => $plan, 'mois' => 1, 'numero_payeur' => '12345'])->assertSessionHasErrors('numero_payeur');
        $this->assertSame(0, CommandeLicence::count());

        // Désactivé : la fonction n'existe pas
        config(['services.djomy.actif' => false]);
        $this->post('/licence/commander', ['plan_id' => $plan, 'mois' => 1, 'numero_payeur' => '622000000'])->assertNotFound();
        $this->get('/abonnement')->assertDontSee('Payer ma licence en ligne');
    }

    public function test_paiement_de_test_isole_de_la_production(): void
    {
        Notification::fake();
        $this->configurerDjomy();
        $this->fauxDjomy();
        [$b] = $this->creerBoutique();
        $commande = CommandeLicence::create(['boutique_id' => $b->id, 'plan_id' => Plan::where('nom', 'Commerce')->value('id'), 'mois' => 12,
            'montant' => 3_600_000, 'transaction_id' => 'TX-123', 'environnement' => 'sandbox']);

        // Une commande de test n'est jamais traitée une fois passé en production
        config(['services.djomy.mode' => 'production']);
        app(\App\Services\Paiement\LicenceEnLigneService::class)->synchroniser($commande);
        $this->assertSame('en_attente', $commande->fresh()->statut);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/status'));

        // En sandbox, elle est traitée, marquée TEST et exclue des encaissements réels
        config(['services.djomy.mode' => 'sandbox']);
        app(\App\Services\Paiement\LicenceEnLigneService::class)->synchroniser($commande);
        $p = PaiementLicence::first();
        $this->assertSame('djomy_test', $p->mode);
        $this->assertSame('Paiement en ligne — TEST', $p->libelleMode());
        $this->assertSame(0, (int) PaiementLicence::reels()->sum('montant'));
    }

    public function test_diagnostic_djomy(): void
    {
        $this->configurerDjomy();
        $this->fauxDjomy();
        $this->artisan('djomy:diagnostic')->expectsOutputToContain('+224 627 40 68 34')
            ->expectsOutputToContain('Djomy a accepté vos identifiants')->assertSuccessful();
    }

    public function test_diagnostic_signale_des_identifiants_refuses(): void
    {
        $this->configurerDjomy();
        Http::fake(['*/v1/auth' => Http::response(['success' => false, 'errors' => ['Invalid api key'], 'status' => 401], 401)]);
        $this->artisan('djomy:diagnostic')->expectsOutputToContain('Invalid api key')->assertFailed();
    }

    public function test_tous_les_moyens_djomy_et_choix_du_client(): void
    {
        Notification::fake();
        $this->configurerDjomy();
        $this->fauxDjomy();
        [$b, $admin] = $this->creerBoutique();
        $plan = Plan::where('nom', 'Commerce')->value('id');
        $this->actingAs($admin)->get('/abonnement')->assertSee('PayCard')->assertSee('Kulu')->assertSee('Soutra Money')->assertSee('Carte bancaire');

        // Sans choix : tous les moyens actifs sont proposés sur la page Djomy ; numéro au format 00224
        $this->post('/licence/commander', ['plan_id' => $plan, 'mois' => 1, 'numero_payeur' => '622 00 00 00'])->assertRedirect();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/gateway') && $r['payerNumber'] === '00224622000000'
            && $r['allowedPaymentMethods'] === ['OM', 'MOMO', 'CARD', 'PAYCARD', 'KULU', 'SOUTRA_MONEY']);

        // Choix du client : seul ce moyen est ouvert
        $this->post('/licence/commander', ['plan_id' => $plan, 'mois' => 1, 'numero_payeur' => '622000000', 'moyen' => 'SOUTRA_MONEY'])->assertRedirect();
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/gateway') && $r['allowedPaymentMethods'] === ['SOUTRA_MONEY']);
        $this->assertSame('SOUTRA_MONEY', CommandeLicence::latest('id')->first()->moyen);

        // Moyen inconnu refusé
        $this->post('/licence/commander', ['plan_id' => $plan, 'mois' => 1, 'numero_payeur' => '622000000', 'moyen' => 'BITCOIN'])->assertSessionHasErrors('moyen');

        // Le moyen réellement utilisé (lu chez Djomy) apparaît sur le paiement enregistré
        $c = CommandeLicence::latest('id')->first();
        $this->get("/licence/retour/{$c->reference}");
        $this->assertSame('KULU', $c->fresh()->moyen_utilise);
        $this->assertStringContainsString('via Kulu', PaiementLicence::first()->note);
    }

    public function test_le_proprietaire_choisit_les_moyens_acceptes(): void
    {
        $this->configurerDjomy();
        $moi = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);
        $base = ['nom_logiciel' => 'Caisse Plus', 'societe' => 'Ma Société SARL', 'djomy_moyens_envoye' => 1];

        $this->actingAs($moi)->put('/admin/parametres', $base + ['djomy_moyens' => ['OM', 'MOMO']])->assertSessionHas('succes');
        $this->assertSame(['OM', 'MOMO'], \App\Support\MoyensDjomy::actifs());

        $this->put('/admin/parametres', $base)->assertSessionHasErrors('djomy_moyens');
        $this->put('/admin/parametres', $base + ['djomy_moyens' => ['OM', 'FAUX']])->assertSessionHasErrors('djomy_moyens.1');
        $this->assertSame(['OM', 'MOMO'], \App\Support\MoyensDjomy::actifs());

        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->get('/abonnement')->assertSee('Orange Money')->assertDontSee('Soutra Money');
    }

    public function test_tous_les_moyens_djomy_presents_partout(): void
    {
        // Catalogue complet, YMO compris (présent mais désactivé par défaut)
        $this->assertSame(['OM', 'MOMO', 'CARD', 'PAYCARD', 'KULU', 'SOUTRA_MONEY', 'YMO'], array_keys(\App\Support\MoyensDjomy::CATALOGUE));
        $this->assertNotContains('YMO', \App\Support\MoyensDjomy::parDefaut());

        // Chaque moyen Djomy a son équivalent dans les modes de paiement de la caisse
        foreach (['OM', 'MOMO', 'CARD', 'PAYCARD', 'KULU', 'SOUTRA_MONEY'] as $code) {
            $this->assertArrayHasKey(\App\Support\MoyensDjomy::modeLocal($code), config('gestion.modes_paiement'), $code);
        }

        // Une vente peut être encaissée en PayCard, Kulu ou Soutra Money
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);
        foreach (['paycard', 'kulu', 'soutra_money'] as $mode) {
            $this->actingAs($admin)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => $mode])
                ->assertSessionHasNoErrors()->assertRedirect();
        }
        $this->assertSame(['kulu', 'paycard', 'soutra_money'],
            $this->dans($b, fn () => \App\Models\Paiement::orderBy('mode')->pluck('mode')->all()));
        $this->get('/caisse')->assertSee('PayCard')->assertSee('Kulu')->assertSee('Soutra Money');

        // Le propriétaire voit les 7 moyens, YMO compris, dans « Ma société »
        $moi = User::create(['prenom' => 'P', 'nom' => 'P', 'email' => 'p@p.gn', 'password' => 'secret123', 'est_super_admin' => true]);
        $this->actingAs($moi)->get('/admin/parametres')->assertSee('moyen_YMO', false)->assertSee('moyen_SOUTRA_MONEY', false);
    }

    public function test_codes_djomy_normalises(): void
    {
        $this->assertSame('CARD', \App\Support\MoyensDjomy::normaliser('ngenius'));
        $this->assertSame('Carte bancaire', \App\Support\MoyensDjomy::libelle('NGENIUS'));
        $this->assertSame('Soutra Money', \App\Support\MoyensDjomy::libelle('soutra_money'));
        $this->assertSame('00224627406834', \App\Services\Paiement\DjomyClient::formatNumero('627 40 68 34'));
    }

    public function test_numero_guineen_normalise(): void
    {
        $this->assertSame('224622000000', numero_guinee('622 00 00 00'));
        $this->assertSame('224622000000', numero_guinee('+224 622-00-00-00'));
        $this->assertSame('224655123456', numero_guinee('00224655123456'));
        $this->assertNull(numero_guinee('722000000'));
        $this->assertNull(numero_guinee(''));
    }
}
