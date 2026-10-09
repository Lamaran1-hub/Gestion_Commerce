<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Appareils où un compte est connecté (sessions enregistrées en base).
 *
 * L'identifiant de session n'est jamais montré dans la page : chaque appareil est désigné par une empreinte
 * (HMAC de l'identifiant), inutilisable pour se faire passer pour lui.
 */
class Appareils
{
    /** @return Collection<int, array{empreinte:string, appareil:string, icone:string, ip:?string, actif:\Carbon\Carbon, courant:bool}> */
    public static function liste(User $user, ?string $sessionCourante): Collection
    {
        if (config('session.driver') !== 'database') {
            return collect();
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->orderByDesc('last_activity')->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($s) => ['empreinte' => self::empreinte($s->id)] + self::decrire($s->user_agent) + [
                'ip' => $s->ip_address, 'actif' => \Carbon\Carbon::createFromTimestamp($s->last_activity), 'courant' => $s->id === $sessionCourante,
            ]);
    }

    /** Déconnecte un appareil du compte (jamais celui d'un autre compte). */
    public static function deconnecter(User $user, string $empreinte): bool
    {
        foreach (DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->pluck('id') as $id) {
            if (hash_equals(self::empreinte($id), $empreinte)) {
                return DB::table(config('session.table', 'sessions'))->where('id', $id)->delete() > 0;
            }
        }

        return false;
    }

    /** Déconnecte tous les appareils du compte, sauf (éventuellement) celui en cours. */
    public static function deconnecterTous(User $user, ?string $sauf = null): int
    {
        return DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->when($sauf, fn ($q) => $q->where('id', '!=', $sauf))->delete();
    }

    public static function empreinte(string $sessionId): string
    {
        return substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 32);
    }

    /** « Chrome sur Android », avec une icône adaptée (téléphone, tablette, ordinateur). */
    public static function decrire(?string $agent): array
    {
        $a = (string) $agent;
        $navigateur = match (true) {
            str_contains($a, 'Edg/') => 'Edge',
            str_contains($a, 'OPR/') || str_contains($a, 'Opera') => 'Opera',
            str_contains($a, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($a, 'Firefox/') => 'Firefox',
            str_contains($a, 'Chrome/') || str_contains($a, 'CriOS') => 'Chrome',
            str_contains($a, 'Safari/') => 'Safari',
            default => 'Navigateur',
        };
        [$systeme, $icone] = match (true) {
            str_contains($a, 'iPad') => ['iPad', 'tablet'],
            str_contains($a, 'iPhone') => ['iPhone', 'phone'],
            str_contains($a, 'Android') => ['Android', str_contains($a, 'Mobile') ? 'phone' : 'tablet'],
            str_contains($a, 'Windows') => ['Windows', 'laptop'],
            str_contains($a, 'Mac OS') => ['Mac', 'laptop'],
            str_contains($a, 'Linux') => ['Linux', 'laptop'],
            default => ['appareil inconnu', 'display'],
        };

        return ['appareil' => "{$navigateur} sur {$systeme}", 'icone' => $icone];
    }
}
