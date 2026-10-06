<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\LigneVente;
use App\Models\NumeroSerie;
use App\Models\Vente;
use Illuminate\Support\Facades\DB;

/**
 * Numéros de série des articles vendus.
 *
 * Règles :
 * - seulement pour les produits « suivi du numéro de série », sur une vente validée ;
 * - pas plus de numéros que d'unités vendues sur la ligne ;
 * - un même numéro ne peut pas être vendu deux fois (hors ventes annulées) : c'est souvent le signe d'une erreur de saisie,
 *   ou d'un appareil déjà vendu ;
 * - les numéros sont normalisés (majuscules, sans espaces ni tirets) pour être retrouvés quelle que soit la saisie.
 */
class NumerosSerie
{
    /** @param  array<int|string, array<int, ?string>>  $saisie  ligne_vente_id => [numéros] */
    public function enregistrer(Vente $vente, array $saisie): int
    {
        if ($vente->statut !== 'validee') {
            throw new OperationRefusee('Cette vente est annulée : on ne peut plus y noter de numéros de série.');
        }

        return DB::transaction(function () use ($vente, $saisie) {
            $lignes = LigneVente::with('produit')->where('vente_id', $vente->id)->whereIn('id', array_keys($saisie))->get()->keyBy('id');
            $total = 0;
            $vus = [];
            foreach ($saisie as $idLigne => $numeros) {
                $l = $lignes[$idLigne] ?? throw new OperationRefusee('Une ligne n\'appartient pas à cette vente.');
                if (! $l->produit?->suivi_serie) {
                    throw new OperationRefusee("« {$l->designation} » n'est pas suivi par numéro de série.");
                }
                $numeros = collect($numeros)->map(fn ($n) => NumeroSerie::normaliser((string) $n))->filter()->values();
                if ($numeros->count() > $l->nombreSeriesAttendues()) {
                    throw new OperationRefusee("« {$l->designation} » : {$numeros->count()} numéros pour {$l->nombreSeriesAttendues()} article(s) vendu(s).");
                }
                foreach ($numeros as $n) {
                    if (isset($vus[$n])) {
                        throw new OperationRefusee("Le numéro {$n} est saisi deux fois.");
                    }
                    $vus[$n] = true;
                    $ailleurs = NumeroSerie::where('numero', $n)->where('vente_id', '!=', $vente->id)
                        ->whereNull('retour_id')->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))   // un appareil rapporté est revendable
                        ->with('vente:id,numero')->first();
                    if ($ailleurs) {
                        throw new OperationRefusee("Le numéro {$n} a déjà été vendu (vente {$ailleurs->vente->numero}). Vérifiez l'appareil ; s'il a été repris, effacez son numéro sur l'ancienne vente.");
                    }
                }
                NumeroSerie::where('ligne_vente_id', $l->id)->whereNull('retour_id')->delete();
                foreach ($numeros as $n) {
                    NumeroSerie::create(['vente_id' => $vente->id, 'ligne_vente_id' => $l->id, 'produit_id' => $l->produit_id, 'numero' => $n]);
                }
                $total += $numeros->count();
            }
            JournalActivite::noter('vente', "Numéros de série notés sur la vente {$vente->numero} ({$total})");

            return $total;
        });
    }

    /** Lignes d'une vente dont des numéros de série manquent encore. */
    public function manquants(Vente $vente): int
    {
        return (int) $vente->lignes->sum(fn (LigneVente $l) => max(0, $l->nombreSeriesAttendues() - $l->numerosSerie->count()));
    }
}
