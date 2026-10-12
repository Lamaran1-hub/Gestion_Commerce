<?php

namespace App\Services;

use App\Models\ChargeFixe;
use App\Models\Depense;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Suivi mensuel des charges fixes : payée, à payer (échéance à venir) ou en retard.
 * Une charge est « payée » pour un mois dès qu'une dépense rattachée tombe dans ce mois.
 */
class ChargesFixes
{
    /**
     * @return Collection<int, array{charge:ChargeFixe, echeance:Carbon, paiement:?Depense, etat:string, jours:int}>
     */
    public function suivi(?Carbon $mois = null): Collection
    {
        $mois = ($mois ?? now())->copy()->startOfMonth();
        $charges = ChargeFixe::where('actif', true)->orderBy('jour')->orderBy('motif')->get();
        $paiements = Depense::whereIn('charge_fixe_id', $charges->pluck('id'))
            ->whereBetween('date_depense', [$mois->toDateString(), $mois->copy()->endOfMonth()->toDateString()])
            ->latest('date_depense')->get()->keyBy('charge_fixe_id');

        return $charges->map(function (ChargeFixe $c) use ($mois, $paiements) {
            $echeance = $c->echeance($mois);
            $paiement = $paiements[$c->id] ?? null;
            $jours = (int) now()->startOfDay()->diffInDays($echeance, false);   // négatif : en retard

            return ['charge' => $c, 'echeance' => $echeance, 'paiement' => $paiement, 'jours' => $jours,
                'etat' => $paiement ? 'payee' : ($jours < 0 ? 'retard' : ($jours <= 7 ? 'bientot' : 'a_venir'))];
        });
    }

    /** Charges du mois en retard ou dues dans les 7 jours (tableau de bord, résumé du jour). */
    public function aPayer(): Collection
    {
        return $this->suivi()->whereIn('etat', ['retard', 'bientot'])->values();
    }

    /** Une charge déjà payée ce mois-là ne se paie pas deux fois par erreur. */
    public function dejaPayee(ChargeFixe $charge, Carbon $date): ?Depense
    {
        return Depense::where('charge_fixe_id', $charge->id)
            ->whereBetween('date_depense', [$date->copy()->startOfMonth()->toDateString(), $date->copy()->endOfMonth()->toDateString()])->first();
    }

    public function totalMensuel(): int
    {
        return (int) ChargeFixe::where('actif', true)->sum('montant');
    }
}
