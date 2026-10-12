<?php

namespace App\Services;

use App\Models\ClotureCaisse;
use App\Models\JournalActivite;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Écarts de caisse par caissier : un manque isolé est souvent une erreur de monnaie,
 * des manques répétés chez la même personne méritent une vérification.
 */
class SuiviEcarts
{
    public function seuilManques(): int
    {
        return max(1, (int) config('gestion.manques_alerte', 3));
    }

    /** @return Collection<int, array{caissier: ?User, clotures:int, manques:int, total_manques:int, excedents:int, total_excedents:int, net:int, fonds_differents:int, reouvertures:int, a_surveiller:bool}> */
    public function parCaissier(string $du, string $au): Collection
    {
        $clotures = ClotureCaisse::with('caissier')->whereDate('jour', '>=', $du)->whereDate('jour', '<=', $au)->orderBy('jour')->get();

        return $clotures->groupBy('user_id')->map(function (Collection $liste, $userId) use ($du, $au) {
            $caissier = $liste->first()->caissier;
            // Fond déclaré différent de ce qui avait été laissé dans le tiroir à la clôture précédente
            $laisse = ClotureCaisse::where('user_id', $userId)->whereDate('jour', '<', $du)->latest('jour')->value('laisse_en_caisse');
            $fondsDifferents = 0;
            foreach ($liste as $c) {
                if ($laisse !== null && (int) $c->fond_caisse !== (int) $laisse) {
                    $fondsDifferents++;
                }
                $laisse = $c->laisse_en_caisse;
            }
            $manques = $liste->where('ecart', '<', 0);
            $excedents = $liste->where('ecart', '>', 0);
            $reouvertures = $caissier ? JournalActivite::where('action', 'caisse')
                ->where('description', 'like', 'Réouverture de la caisse de '.$caissier->nomComplet().' du %')
                ->whereDate('created_at', '>=', $du)->whereDate('created_at', '<=', $au)->count() : 0;

            return [
                'caissier' => $caissier,
                'clotures' => $liste->count(),
                'manques' => $manques->count(),
                'total_manques' => (int) -$manques->sum('ecart'),
                'excedents' => $excedents->count(),
                'total_excedents' => (int) $excedents->sum('ecart'),
                'net' => (int) $liste->sum('ecart'),
                'fonds_differents' => $fondsDifferents,
                'reouvertures' => $reouvertures,
                'a_surveiller' => $manques->count() >= $this->seuilManques(),
            ];
        })->sortByDesc(fn ($l) => [(int) $l['a_surveiller'], $l['total_manques']])->values();
    }

    /** Caissiers à surveiller sur les 30 derniers jours (rappel du tableau de bord). */
    public function aSurveiller(): Collection
    {
        return $this->parCaissier(now()->subDays(30)->toDateString(), now()->toDateString())->where('a_surveiller', true)->values();
    }
}
