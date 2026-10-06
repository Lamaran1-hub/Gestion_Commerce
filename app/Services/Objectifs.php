<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;

/**
 * Objectifs de vente du mois et commissions.
 *
 * - Réalisé = chiffre d'affaires TTC des ventes validées du mois (retours déduits), comme au tableau de bord.
 * - Projection = rythme moyen par jour écoulé × nombre de jours du mois.
 * - Reste à vendre par jour : réparti sur les jours restants, aujourd'hui compris.
 * - Commission d'un vendeur = son % × son chiffre d'affaires hors taxes du mois (la TVA n'est pas à la boutique).
 */
class Objectifs
{
    /** @return array{0:Carbon,1:Carbon} */
    public static function bornes(?Carbon $mois = null): array
    {
        $m = ($mois ?? now())->copy();

        return [$m->copy()->startOfMonth(), $m->copy()->endOfMonth()];
    }

    /** Suivi d'un objectif : réalisé, %, projection de fin de mois, reste à faire par jour. */
    public function suivi(?int $objectif, int $realise, ?Carbon $mois = null): array
    {
        [$debut, $fin] = self::bornes($mois);
        $aujourdhui = now()->between($debut, $fin) ? now() : $fin;
        $joursEcoules = max(1, (int) $debut->diffInDays($aujourdhui->copy()->startOfDay()) + 1);
        $joursMois = (int) $debut->daysInMonth;
        // Jours restants aujourd'hui compris : la journée en cours peut encore compter
        $joursRestants = now()->between($debut, $fin) ? $joursMois - $joursEcoules + 1 : 0;
        $projection = (int) round($realise / $joursEcoules * $joursMois);

        return [
            'objectif' => $objectif,
            'realise' => $realise,
            'pct' => $objectif ? min(999, (int) floor($realise * 100 / $objectif)) : null,
            'projection' => $projection,
            'atteindra' => $objectif ? $projection >= $objectif : null,
            'jours_restants' => $joursRestants,
            'par_jour' => $objectif && $joursRestants > 0 ? (int) ceil(max(0, $objectif - $realise) / $joursRestants) : 0,
        ];
    }

    public function boutique(?Carbon $mois = null): array
    {
        [$debut, $fin] = self::bornes($mois);

        return $this->suivi(boutique()->objectif_mensuel, (int) Vente::validees()->whereBetween('date_vente', [$debut, $fin])->sum('total_ttc'), $mois);
    }

    public function vendeur(User $user, ?Carbon $mois = null): array
    {
        [$debut, $fin] = self::bornes($mois);
        $ventes = Vente::validees()->where('user_id', $user->id)->whereBetween('date_vente', [$debut, $fin]);
        $suivi = $this->suivi($user->objectif_mensuel, (int) (clone $ventes)->sum('total_ttc'), $mois);
        $ht = (int) (clone $ventes)->sum('total_ttc') - (int) (clone $ventes)->sum('total_tva');

        return $suivi + [
            'nb_ventes' => (clone $ventes)->count(),
            'ca_ht' => $ht,
            'commission' => $user->commission_pct ? (int) round($ht * (float) $user->commission_pct / 100) : 0,
        ];
    }

    /** Classement de l'équipe du mois (vendeurs actifs), meilleur chiffre d'affaires d'abord. */
    public function equipe(?Carbon $mois = null): \Illuminate\Support\Collection
    {
        return User::where('boutique_id', boutique()->id)->where('actif', true)->with('role')->get()
            ->filter(fn (User $u) => $u->aPermission('ventes.creer'))
            ->map(fn (User $u) => ['user' => $u] + $this->vendeur($u, $mois))
            ->sortByDesc('realise')->values();
    }
}
