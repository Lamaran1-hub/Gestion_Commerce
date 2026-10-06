<?php

namespace App\Support;

use App\Models\EmailEnvoye;
use App\Models\User;
use Illuminate\Notifications\Notification as NotificationBase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Point d'envoi unique des e-mails du logiciel.
 *
 * - Le serveur d'envoi (SMTP) se règle dans l'espace du propriétaire ; à défaut, la configuration .env.
 * - Chaque envoi est tracé (envoyé, simulé si l'envoi réel n'est pas configuré, ou en échec avec la raison).
 * - Un échec d'e-mail ne bloque jamais l'opération qui l'a déclenché (paiement, rappel…).
 * - Les nouveautés ne sont envoyées qu'aux personnes qui ne s'en sont pas désabonnées ;
 *   les e-mails liés au compte et à la licence partent toujours.
 */
class Courrier
{
    /** Applique la configuration SMTP enregistrée par le propriétaire (appelé au démarrage). */
    /** Transport d'origine (.env), pour y revenir quand le propriétaire désactive l'envoi réel. */
    private static ?string $transportOrigine = null;

    public static function configurer(): void
    {
        self::$transportOrigine ??= config('mail.default');
        $p = Plateforme::tout();
        if (($p['emails_actifs'] ?? '0') !== '1') {
            config(['mail.default' => self::$transportOrigine]);
        } elseif (! empty($p['smtp_hote'])) {
            $motDePasse = null;
            if (! empty($p['smtp_mot_de_passe'])) {
                try {
                    $motDePasse = Crypt::decryptString($p['smtp_mot_de_passe']);
                } catch (\Throwable) {
                    $motDePasse = null; // clé d'application changée : il faudra ressaisir le mot de passe
                }
            }
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $p['smtp_hote'],
                'mail.mailers.smtp.port' => (int) ($p['smtp_port'] ?? 587),
                'mail.mailers.smtp.scheme' => ($p['smtp_chiffrement'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
                'mail.mailers.smtp.username' => $p['smtp_utilisateur'] ?? null,
                'mail.mailers.smtp.password' => $motDePasse,
                'mail.mailers.smtp.timeout' => 20,
            ]);
        }
        if (! empty($p['expediteur_email'])) {
            config(['mail.from.address' => $p['expediteur_email']]);
        }
        config(['mail.from.name' => $p['expediteur_nom'] ?? ($p['societe'] ?? config('app.name'))]);
    }

    /** L'envoi réel est-il configuré ? (sinon les e-mails sont seulement écrits dans le journal technique) */
    public static function reel(): bool
    {
        return ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    /**
     * Envoie une notification par e-mail à des utilisateurs, en traçant chaque envoi.
     *
     * @param  iterable<User>  $destinataires
     * @return int nombre d'e-mails partis (ou simulés)
     */
    public static function envoyer(iterable $destinataires, NotificationBase $notification, string $type, ?int $boutiqueId = null, bool $nouveaute = false): int
    {
        $partis = 0;
        foreach ($destinataires as $user) {
            if (! $user->email || ($nouveaute && ! $user->recevoir_nouveautes)) {
                continue;
            }
            $partis += (int) self::expedier(fn () => Notification::sendNow($user, $notification, ['mail']),
                $user->email, self::sujet($notification, $user), $type, $boutiqueId ?? $user->boutique_id, $user->id);
        }

        return $partis;
    }

    /** Envoi à une adresse libre (e-mail de test, contact sans compte). */
    public static function envoyerA(string $email, NotificationBase $notification, string $type, ?int $boutiqueId = null): bool
    {
        $destinataire = Notification::route('mail', $email);

        return self::expedier(fn () => Notification::sendNow($destinataire, $notification, ['mail']),
            $email, self::sujet($notification, $destinataire), $type, $boutiqueId, null);
    }

    private static function expedier(callable $envoi, string $email, string $sujet, string $type, ?int $boutiqueId, ?int $userId): bool
    {
        $trace = ['destinataire' => $email, 'sujet' => mb_substr($sujet, 0, 200), 'type' => $type, 'boutique_id' => $boutiqueId, 'user_id' => $userId];
        try {
            $envoi();
            EmailEnvoye::create($trace + ['statut' => self::reel() ? 'envoye' : 'simule']);

            return true;
        } catch (\Throwable $e) {
            EmailEnvoye::create($trace + ['statut' => 'echec', 'erreur' => mb_substr($e->getMessage(), 0, 1000)]);
            Log::warning('E-mail non envoyé', ['destinataire' => $email, 'type' => $type, 'erreur' => $e->getMessage()]);

            return false;
        }
    }

    private static function sujet(NotificationBase $notification, object $destinataire): string
    {
        try {
            return (string) ($notification->toMail($destinataire)->subject ?? 'E-mail');
        } catch (\Throwable) {
            return class_basename($notification);
        }
    }

    /** Recharge la configuration après modification (le transport SMTP déjà créé est oublié). */
    public static function recharger(): void
    {
        self::configurer();
        Mail::purge('smtp');
    }
}
