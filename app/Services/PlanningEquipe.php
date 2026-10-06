<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\User;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Planning des équipes : horaires prévus, couverture des heures d'affluence, écarts avec les pointages.
 *
 * - Affluence : part du chiffre d'affaires de chaque heure, pour chaque jour de la semaine,
 *   calculée sur les 4 dernières semaines.
 * - Renfort conseillé : heure chargée (au moins 1,5 fois la moyenne du jour) couverte par une seule personne ou moins.
 * - Retard : arrivée pointée plus de 10 minutes après l'heure prévue ; absence : jour prévu passé sans pointage.
 */
class PlanningEquipe
{
    public const TOLERANCE_RETARD_MINUTES = 10;

    public const HEURES = [6, 22]; // plage affichée

    public function employes(): Collection
    {
        return User::where('boutique_id', boutique()->id)->where('actif', true)->orderBy('prenom')->get();
    }

    /** @return array<int, array<string, Planning>> user_id => [Y-m-d => horaire] */
    public function semaine(Carbon $lundi): array
    {
        $grille = [];
        foreach (Planning::whereDate('jour', '>=', $lundi->toDateString())->whereDate('jour', '<=', $lundi->copy()->addDays(6)->toDateString())->get() as $p) {
            $grille[$p->user_id][$p->jour->toDateString()] = $p;
        }

        return $grille;
    }

    /** @param array<int, array<string, array{debut:?string, fin:?string}>> $horaires */
    public function enregistrer(Carbon $lundi, array $horaires, User $auteur): int
    {
        $employes = $this->employes()->keyBy('id');
        $jours = collect(range(0, 6))->map(fn ($i) => $lundi->copy()->addDays($i)->toDateString())->all();

        return DB::transaction(function () use ($horaires, $employes, $jours, $lundi, $auteur) {
            $n = 0;
            foreach ($horaires as $userId => $parJour) {
                abort_unless($employes->has($userId), 404);
                foreach ($parJour as $jour => $h) {
                    if (! in_array($jour, $jours, true)) {
                        continue;
                    }
                    $debut = trim((string) ($h['debut'] ?? ''));
                    $fin = trim((string) ($h['fin'] ?? ''));
                    if ($debut === '' && $fin === '') {
                        Planning::where('user_id', $userId)->whereDate('jour', $jour)->delete();

                        continue;
                    }
                    if (! preg_match('/^\d{2}:\d{2}$/', $debut) || ! preg_match('/^\d{2}:\d{2}$/', $fin) || $fin <= $debut) {
                        throw new OperationRefusee($employes[$userId]->nomComplet().' le '.Carbon::parse($jour)->format('d/m')
                            .' : indiquez une heure de début et une heure de fin (la fin après le début).');
                    }
                    $existant = Planning::where('user_id', $userId)->whereDate('jour', $jour)->first();
                    $existant ? $existant->update(['debut' => $debut, 'fin' => $fin])
                        : Planning::create(['user_id' => $userId, 'jour' => $jour, 'debut' => $debut, 'fin' => $fin]);
                    $n++;
                }
            }
            JournalActivite::noter('equipe', 'Planning de la semaine du '.$lundi->format('d/m/Y')." enregistré par {$auteur->nomComplet()} ({$n} horaire(s))");

            return $n;
        });
    }

    /** Recopie la semaine précédente sur la semaine choisie (sans écraser ce qui existe déjà). */
    public function copierSemainePrecedente(Carbon $lundi): int
    {
        $n = 0;
        foreach (Planning::whereDate('jour', '>=', $lundi->copy()->subWeek()->toDateString())->whereDate('jour', '<=', $lundi->copy()->subDay()->toDateString())->get() as $p) {
            $jour = $p->jour->copy()->addWeek()->toDateString();
            if (! Planning::where('user_id', $p->user_id)->whereDate('jour', $jour)->exists()) {
                Planning::create(['user_id' => $p->user_id, 'jour' => $jour, 'debut' => substr($p->debut, 0, 5), 'fin' => substr($p->fin, 0, 5)]);
                $n++;
            }
        }

        return $n;
    }

    /**
     * Couverture et affluence : pour chaque jour de la semaine et chaque heure,
     * nombre de personnes prévues, part du CA habituelle, et renfort conseillé.
     */
    public function couverture(Carbon $lundi, array $grille): array
    {
        // Affluence : CA des 4 dernières semaines par jour de semaine (1-7) et par heure
        $affluence = [];
        foreach (Vente::validees()->where('date_vente', '>=', now()->subWeeks(4)->startOfDay())->select(['date_vente', 'total_ttc'])->cursor() as $v) {
            $affluence[(int) $v->date_vente->format('N')][(int) $v->date_vente->format('G')] = ($affluence[(int) $v->date_vente->format('N')][(int) $v->date_vente->format('G')] ?? 0) + $v->total_ttc;
        }
        $resultat = [];
        foreach (range(0, 6) as $i) {
            $jour = $lundi->copy()->addDays($i);
            $n = (int) $jour->format('N');
            $totalJour = array_sum($affluence[$n] ?? []);
            $heuresOuvertes = count(array_filter($affluence[$n] ?? []));
            $moyenne = $heuresOuvertes ? $totalJour / $heuresOuvertes : 0;
            foreach (range(self::HEURES[0], self::HEURES[1] - 1) as $h) {
                $presents = 0;
                foreach ($grille as $parJour) {
                    $p = $parJour[$jour->toDateString()] ?? null;
                    if ($p && substr($p->debut, 0, 5) <= sprintf('%02d:00', $h) && substr($p->fin, 0, 5) > sprintf('%02d:00', $h)) {
                        $presents++;
                    }
                }
                $ca = $affluence[$n][$h] ?? 0;
                $resultat[$jour->toDateString()][$h] = [
                    'presents' => $presents,
                    'part' => $totalJour ? round($ca * 100 / $totalJour) : 0,
                    'renfort' => $moyenne > 0 && $ca >= 1.5 * $moyenne && $presents <= 1,
                ];
            }
        }

        return $resultat;
    }

    /** Retards et absences de la semaine, comparés aux pointages. */
    public function ecarts(Carbon $lundi, array $grille): array
    {
        $pointages = Pointage::whereBetween('arrivee', [$lundi->copy()->startOfDay(), $lundi->copy()->addDays(6)->endOfDay()])->get()
            ->groupBy(fn ($p) => $p->user_id.'|'.$p->arrivee->toDateString());
        $ecarts = [];
        foreach ($grille as $userId => $parJour) {
            foreach ($parJour as $jour => $p) {
                $pointe = $pointages->get($userId.'|'.$jour)?->sortBy('arrivee')->first();
                $prevu = Carbon::parse($jour.' '.substr($p->debut, 0, 5));
                if (! $pointe) {
                    if ($prevu->copy()->addHours(2)->isPast()) {
                        $ecarts[] = ['user_id' => $userId, 'jour' => $jour, 'type' => 'absence', 'minutes' => 0];
                    }
                } elseif (($retard = (int) $prevu->diffInMinutes($pointe->arrivee, false)) > self::TOLERANCE_RETARD_MINUTES) {
                    $ecarts[] = ['user_id' => $userId, 'jour' => $jour, 'type' => 'retard', 'minutes' => $retard];
                }
            }
        }

        return $ecarts;
    }
}
