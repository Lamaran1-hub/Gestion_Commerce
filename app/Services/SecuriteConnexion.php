<?php

namespace App\Services;

use App\Models\Connexion;
use App\Models\User;
use App\Notifications\AlerteSecuriteCompte;
use App\Support\Appareils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Historique des connexions et alertes :
 *  - chaque appareil reçoit un témoin aléatoire à sa première connexion ; une connexion réussie sans ce témoin
 *    (téléphone neuf, autre ordinateur… ou quelqu'un d'autre) prévient le titulaire par e-mail ;
 *  - un compte bloqué par des mots de passe faux répétés prévient aussi son titulaire.
 */
class SecuriteConnexion
{
    public const TEMOIN = 'gn_appareil';

    public const CONSERVATION_JOURS = 180;

    public function echec(string $email, Request $request, bool $bloque): void
    {
        $user = User::where('email', Str::lower($email))->first();
        Connexion::create(['user_id' => $user?->id, 'email' => mb_substr(Str::lower($email), 0, 190), 'ip' => $request->ip(),
            'appareil' => Appareils::decrire($request->userAgent())['appareil'], 'reussie' => false]);
        // Compte bloqué par des essais répétés : le titulaire est prévenu (une fois par blocage)
        if ($bloque && $user && $user->actif) {
            $this->alerter($user, 'Plusieurs mots de passe incorrects ont été saisis pour votre compte : la connexion est bloquée 15 minutes', $request);
        }
    }

    public function succes(User $user, Request $request): Connexion
    {
        $temoin = (string) $request->cookie(self::TEMOIN);
        $jeton = $temoin !== '' ? hash('sha256', $temoin) : null;
        $connu = $jeton && Connexion::where('user_id', $user->id)->where('jeton_appareil', $jeton)->where('reussie', true)->exists();
        $dejaConnecte = Connexion::where('user_id', $user->id)->where('reussie', true)->exists();
        $echecs = $dejaConnecte ? Connexion::where('user_id', $user->id)->where('reussie', false)
            ->where('created_at', '>', Connexion::where('user_id', $user->id)->where('reussie', true)->max('created_at'))->count() : 0;

        if (! $connu) {
            $temoin = Str::random(40);
            $jeton = hash('sha256', $temoin);
        }
        // Témoin de l'appareil : 2 ans, inaccessible au JavaScript de la page
        Cookie::queue(Cookie::make(self::TEMOIN, $temoin, 60 * 24 * 730, null, null, null, true, false, 'lax'));

        $c = Connexion::create(['user_id' => $user->id, 'email' => $user->email, 'ip' => $request->ip(),
            'appareil' => Appareils::decrire($request->userAgent())['appareil'], 'reussie' => true,
            'nouvel_appareil' => ! $connu && $dejaConnecte, 'jeton_appareil' => $jeton]);

        // Première connexion de l'appareil (sauf toute première connexion du compte) : le titulaire est prévenu
        if (! $connu && $dejaConnecte) {
            $this->alerter($user, 'Votre compte vient d\'être ouvert sur un nouvel appareil'
                .($echecs ? " (après {$echecs} mot(s) de passe incorrect(s))" : ''), $request);
        }
        Connexion::where('user_id', $user->id)->where('created_at', '<', now()->subDays(self::CONSERVATION_JOURS))->delete();

        return $c;
    }

    /** @return \Illuminate\Support\Collection<int, Connexion> */
    public function dernieres(User $user, int $nombre = 10)
    {
        return Connexion::where('user_id', $user->id)->latest('created_at')->latest('id')->limit($nombre)->get();
    }

    /** Mots de passe faux des dernières 24 h, par utilisateur (liste de l'équipe). */
    public static function echecsRecents(iterable $userIds): array
    {
        return Connexion::whereIn('user_id', collect($userIds)->all())->where('reussie', false)->where('created_at', '>', now()->subDay())
            ->selectRaw('user_id, COUNT(*) as n')->groupBy('user_id')->pluck('n', 'user_id')->map(fn ($n) => (int) $n)->all();
    }

    private function alerter(User $user, string $evenement, Request $request): void
    {
        try {
            $user->notify(new AlerteSecuriteCompte($user->prenom, $evenement, Appareils::decrire($request->userAgent())['appareil'].' ('.$request->ip().')'));
        } catch (\Throwable $e) {
            report($e);   // un e-mail qui ne part pas ne bloque jamais la connexion
        }
    }
}
