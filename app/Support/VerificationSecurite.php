<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Contrôle de la configuration de sécurité (avant et après la mise en ligne).
 * Ne lit jamais la valeur d'un secret pour l'afficher : seulement sa présence ou sa qualité.
 *
 * Niveaux : critique (à corriger avant d'ouvrir le site), attention (recommandé), ok.
 */
class VerificationSecurite
{
    /** Mot de passe d'installation livré dans .env.example */
    private const MOT_DE_PASSE_EXEMPLE = 'ChangezMoi2026!';

    /** @return array<int, array{niveau:string, titre:string, conseil:string}> */
    public static function controles(): array
    {
        $prod = app()->isProduction();
        $c = [];
        $ajouter = function (bool $ok, string $niveauSiKo, string $titre, string $conseil) use (&$c) {
            $c[] = ['niveau' => $ok ? 'ok' : $niveauSiKo, 'titre' => $titre, 'conseil' => $ok ? '' : $conseil];
        };

        $ajouter(strlen((string) config('app.key')) >= 32, 'critique', 'Clé de chiffrement (APP_KEY)',
            'Générez-la une seule fois avec « php artisan key:generate » et gardez-en une copie sûre : sans elle, les mots de passe chiffrés (e-mails) sont illisibles.');
        $ajouter($prod, 'attention', 'Mode production (APP_ENV=production)',
            "En ligne, mettez APP_ENV=production : cookies sécurisés, HTTPS imposé, pages d'erreur sans détails.");
        $ajouter(! ($prod && config('app.debug')), 'critique', 'Mode débogage désactivé en ligne (APP_DEBUG=false)',
            "APP_DEBUG=true en production affiche le code et la configuration à n'importe quel visiteur lors d'une erreur.");
        $ajouter(! $prod || str_starts_with((string) config('app.url'), 'https://'), 'critique', 'Adresse en HTTPS (APP_URL)',
            'Le site doit être servi en HTTPS (certificat SSL gratuit sur Hostinger) et APP_URL doit commencer par https://.');
        $ajouter(! $prod || config('session.secure') === true, 'critique', 'Cookie de session sécurisé',
            "Mettez SESSION_SECURE_COOKIE=true (ou supprimez la ligne : c'est le réglage par défaut en production).");
        $ajouter((bool) config('session.encrypt'), 'attention', 'Sessions chiffrées (SESSION_ENCRYPT=true)',
            'Les sessions enregistrées en base sont lisibles en clair : mettez SESSION_ENCRYPT=true.');
        $ajouter(! $prod || config('logging.channels.single.level') !== 'debug', 'attention', 'Journal technique sans détails de débogage (LOG_LEVEL)',
            'Mettez LOG_LEVEL=warning en production.');

        // Mots de passe connus de tous : compte de démonstration, mot de passe d'installation
        $motsConnus = array_unique(array_filter(['demo1234', self::MOT_DE_PASSE_EXEMPLE, 'password', 'secret123', config('gestion.super_admin.password')]));
        $faibles = User::withoutGlobalScopes()->where('actif', true)
            ->where(fn ($q) => $q->where('est_super_admin', true)->orWhere('email', 'demo@gngestion.com'))
            ->get(['id', 'email', 'password'])
            ->filter(fn ($u) => collect($motsConnus)->contains(fn ($m) => Hash::check($m, $u->password)));
        if ($faibles->isEmpty()) {
            $ajouter(true, 'critique', 'Aucun compte avec un mot de passe connu', '');
        } else {
            $ajouter(false, $prod ? 'critique' : 'attention', 'Comptes avec un mot de passe connu ('.$faibles->count().')',
                ($prod ? '' : 'Sans danger en local, mais à corriger avant la mise en ligne. ')
                .'Changez le mot de passe (ou supprimez le compte de démonstration) : '.$faibles->pluck('email')->implode(', ').'.');
        }
        $ajouter(! $prod || config('gestion.super_admin.password') === self::MOT_DE_PASSE_EXEMPLE, 'attention', "Mot de passe d'installation retiré du fichier .env",
            'Une fois le compte propriétaire créé, supprimez la ligne SUPER_ADMIN_PASSWORD du .env (le mot de passe se change ensuite dans Mon profil).');

        // Paiement en ligne : identifiants présents et mode cohérent
        if (config('services.djomy.actif')) {
            $ajouter(config('services.djomy.client_id') && config('services.djomy.client_secret'), 'critique', 'Identifiants Djomy renseignés',
                'DJOMY_CLIENT_ID et DJOMY_CLIENT_SECRET sont nécessaires : sans eux, la signature des notifications de paiement ne peut pas être vérifiée.');
            $ajouter(! $prod || config('services.djomy.mode') === 'production', 'attention', 'Djomy en mode production',
                'DJOMY_MODE=sandbox : les paiements ne sont que des tests.');
        }

        // Fichiers sensibles jamais dans le dossier public
        $exposes = collect(['.env', '.env.backup', 'composer.json', 'database.sqlite'])->filter(fn ($f) => file_exists(public_path($f)));
        $ajouter($exposes->isEmpty(), 'critique', 'Aucun fichier sensible dans public/', 'Retirez de public/ : '.$exposes->implode(', ').'.');
        $ajouter(file_exists(storage_path('app/public/.htaccess')), 'attention', 'Exécution de scripts bloquée dans les fichiers envoyés',
            "Le fichier storage/app/public/.htaccess manque (livré avec l'application) : remettez-le.");
        if (DIRECTORY_SEPARATOR === '/' && file_exists(base_path('.env'))) {
            $ajouter((fileperms(base_path('.env')) & 0o004) === 0, 'attention', 'Fichier .env non lisible par les autres comptes du serveur',
                'Sur le serveur : chmod 640 .env');
        }
        $ajouter(! $prod || ! in_array(config('mail.default'), ['log', 'array'], true), 'attention', 'Envoi réel des e-mails',
            "Les e-mails (mot de passe oublié, rappels) ne partent pas : réglez le serveur d'envoi dans Administration → E-mails.");

        return $c;
    }

    /** Points à corriger (calcul coûteux : vérifie les mots de passe), gardé une heure pour le tableau de bord. */
    public static function aCorriger(bool $recalculer = false): array
    {
        if ($recalculer) {
            cache()->forget('securite.a_corriger');
        }

        return cache()->remember('securite.a_corriger', now()->addHour(),
            fn () => array_values(array_filter(self::controles(), fn ($c) => $c['niveau'] !== 'ok')));
    }
}
