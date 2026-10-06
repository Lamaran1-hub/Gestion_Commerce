<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

use App\Models\Boutique;
use App\Models\JournalActivite;
use App\Models\User;
use Database\Seeders\PlanSeeder;

// --- Licences (côté éditeur du logiciel) ---

Artisan::command('plateforme:installer', function () {
    $this->callSilent('db:seed', ['--class' => PlanSeeder::class, '--force' => true]);
    $u = User::firstOrCreate(['email' => config('gestion.super_admin.email')], [
        'prenom' => 'Administrateur', 'nom' => 'Plateforme',
        'password' => config('gestion.super_admin.password'), 'est_super_admin' => true,
    ]);
    $this->info('Formules créées. Super-administrateur : '.$u->email.($u->wasRecentlyCreated ? ' (mot de passe : SUPER_ADMIN_PASSWORD du .env, à changer)' : ' (existait déjà)'));
})->purpose('Crée les formules et le compte super-administrateur (sans données de démonstration)');

Artisan::command('licence:etat', function () {
    $this->table(['Slug', 'Boutique', 'Formule', 'État', 'Échéance', 'Logo personnalisé'], Boutique::with('plan')->orderBy('nom')->get()->map(fn (Boutique $b) => [
        $b->slug, $b->nom, $b->plan?->nom ?? '—', $b->libelleStatut(),
        $b->abonnement_expire_le?->format('d/m/Y') ?? 'illimitée', $b->licencePayee() ? 'affiché' : 'masqué (licence non payée)',
    ]));
})->purpose('Liste les boutiques et l\'état de leur licence');

Artisan::command('licence:activer {boutique : slug ou id de la boutique} {--mois=12 : durée payée en mois} {--illimitee : licence sans échéance}', function () {
    $b = Boutique::where('slug', $this->argument('boutique'))->orWhere('id', $this->argument('boutique'))->first();
    if (! $b) {
        return $this->error('Boutique introuvable. Voir : php artisan licence:etat');
    }
    $depart = $b->abonnement_expire_le && $b->abonnement_expire_le->isFuture() ? $b->abonnement_expire_le : now();
    $b->update([
        'statut' => 'actif',
        'abonnement_expire_le' => $this->option('illimitee') ? null : $depart->copy()->addMonthsNoOverflow((int) $this->option('mois')),
    ]);
    $this->info("Licence de « {$b->nom} » activée ".($b->abonnement_expire_le ? "jusqu'au ".$b->abonnement_expire_le->format('d/m/Y') : 'sans échéance').'.');
})->purpose('Enregistre le paiement d\'une licence : la boutique passe en « actif » (logo personnalisé affiché)');

Artisan::command('licence:suspendre {boutique}', function () {
    $b = Boutique::where('slug', $this->argument('boutique'))->orWhere('id', $this->argument('boutique'))->first();
    if (! $b) {
        return $this->error('Boutique introuvable.');
    }
    $b->update(['statut' => 'suspendu']);
    $this->warn("« {$b->nom} » est suspendue : ses utilisateurs ne peuvent plus travailler (données conservées).");
})->purpose('Suspend une boutique (impayé)');

// --- Paiements en ligne : rattrapage si un webhook n'est pas arrivé ---

Artisan::command('licences:verifier-paiements', function (App\Services\Paiement\LicenceEnLigneService $service) {
    $commandes = App\Models\CommandeLicence::enAttente()->where('created_at', '<=', now()->subMinutes(2))->get();
    foreach ($commandes as $commande) {
        $apres = $service->synchroniser($commande);
        if ($apres->statut !== 'en_attente') {
            $this->line("{$apres->reference} : {$apres->libelleStatut()}");
        }
    }
    $this->info($commandes->count().' paiement(s) en attente vérifié(s).');
})->purpose('Vérifie chez Djomy les paiements de licence restés en attente, et expire les commandes abandonnées');

// Planification (sur le serveur : une tâche cron « * * * * * php artisan schedule:run »)
Illuminate\Support\Facades\Schedule::command('licences:verifier-paiements')->everyFiveMinutes()->withoutOverlapping();
Illuminate\Support\Facades\Schedule::command('auth:clear-resets')->daily(); // jetons de réinitialisation expirés

// --- Diagnostic Djomy : à lancer en sandbox avant d'accepter de vrais paiements ---

Artisan::command('djomy:diagnostic
    {--paiement : crée aussi un paiement de test de 1 000 GNF et affiche le lien à ouvrir}
    {--numero= : numéro payeur du paiement de test (ex. 622000000)}
    {--statut= : lit le statut d\'une transaction Djomy (transactionId)}', function (App\Services\Paiement\DiagnosticDjomy $diagnostic, App\Services\Paiement\DjomyClient $djomy) {
    $etapes = $diagnostic->executer();
    foreach ($etapes as [$libelle, $ok, $detail]) {
        $this->line(($ok === true ? '<info>[OK]</info>   ' : ($ok === false ? '<error>[KO]</error>   ' : '<comment>[!]</comment>    ')).$libelle.' — '.$detail);
    }
    if (App\Services\Paiement\DiagnosticDjomy::bloquant($etapes)) {
        $this->error('Des points bloquants empêchent les paiements en ligne.');

        return 1;
    }

    if ($transaction = $this->option('statut')) {
        $etat = $djomy->statut($transaction);
        $this->info("Transaction {$transaction} : ".($etat['statut'] ?? 'inconnu').' — montant '.($etat['montant'] ?? '?'));
        $this->line(json_encode($etat['brut'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    if ($this->option('paiement')) {
        if (config('services.djomy.mode') !== 'sandbox' && ! $this->confirm('Vous êtes en PRODUCTION : ce paiement serait réel. Continuer ?')) {
            return 1;
        }
        $numero = numero_guinee($this->option('numero') ?: $this->ask('Numéro payeur de test (ex. 622000000)'));
        if (! $numero) {
            $this->error('Numéro invalide.');

            return 1;
        }
        $reference = 'DIAG-'.now()->format('ymdHis');
        $p = $djomy->creerPaiement(1000, $numero, $reference, 'Diagnostic '.config('app.name'),
            url('/abonnement'), url('/abonnement'), ['diagnostic' => true]);
        $this->info("Paiement de test créé : transaction {$p['transaction_id']} (statut {$p['statut']})");
        $this->line("Ouvrez ce lien pour payer : {$p['url']}");
        $this->line("Puis vérifiez : php artisan djomy:diagnostic --statut={$p['transaction_id']}");
        $this->line(json_encode($p['brut'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    return 0;
})->purpose('Vérifie la configuration Djomy (mode, identifiants, webhook) et permet un paiement de test');

// --- Relances automatiques d'échéance des licences (J-7, J-3, J-1, J, J+1) ---

Artisan::command('licences:rappels', function (App\Services\RappelsLicence $rappels) {
    $this->info($rappels->envoyer().' boutique(s) relancée(s).');
})->purpose('Prévient les boutiques dont la licence arrive à échéance (application + e-mail)');

Illuminate\Support\Facades\Schedule::command('licences:rappels')->dailyAt('08:00')->withoutOverlapping();

// --- Résumé du matin pour les gérants (activité de la veille, alertes) ---

Artisan::command('boutiques:resume', function (App\Services\ResumeQuotidien $resume) {
    $this->info($resume->envoyer().' résumé(s) envoyé(s).');
})->purpose('Envoie aux gérants le résumé de la veille (ventes, caisses, stock bas, crédits en retard)');

Illuminate\Support\Facades\Schedule::command('boutiques:resume')->dailyAt('07:00')->withoutOverlapping();

// --- Sauvegarde automatique de la base (chaque nuit) ---

Artisan::command('sauvegarde:creer', function (App\Services\Sauvegarde $sauvegarde) {
    $nom = $sauvegarde->creer();
    $supprimees = $sauvegarde->purger(config('gestion.sauvegardes_a_garder'));
    $this->info("Sauvegarde {$nom} créée ({$supprimees} ancienne(s) supprimée(s)).");
})->purpose('Sauvegarde complète de la base en .sql.gz (storage/app/sauvegardes)');

Illuminate\Support\Facades\Schedule::command('sauvegarde:creer')->dailyAt('02:00')->withoutOverlapping()
    ->onFailure(fn () => Illuminate\Support\Facades\Log::critical('La sauvegarde automatique de la base a échoué.'));

// --- Archives fiscales mensuelles signées (conservation 6 ans) ---

Artisan::command('archives:mensuelles', function (App\Services\ArchivesFiscales $archives) {
    $this->info($archives->archiverMoisPrecedent().' archive(s) du mois précédent générée(s).');
})->purpose('Génère l\'archive signée du mois précédent pour chaque boutique active');

Illuminate\Support\Facades\Schedule::command('archives:mensuelles')->monthlyOn(1, '03:00')->withoutOverlapping();
