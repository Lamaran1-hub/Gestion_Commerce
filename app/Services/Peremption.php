<?php

namespace App\Services;

use App\Models\Produit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lots qui périment bientôt (ou déjà périmés) encore en rayon.
 *
 * Le stock est tenu par produit, pas par lot : on estime ce qui reste de chaque réception
 * selon la règle « premier entré, premier sorti » (le stock restant correspond aux réceptions
 * les plus récentes). C'est la façon dont un commerçant range et vend sa marchandise.
 */
class Peremption
{
    /** @return Collection<int, array{produit:Produit, ligne_id:int, numero:string, date_appro:string, date_peremption:\Carbon\Carbon, jours:int, quantite:float, valeur:int}> */
    public function lots(?int $jours = null): Collection
    {
        $jours ??= boutique()->alerte_peremption_jours ?: 30;
        $limite = now()->addDays($jours)->toDateString();

        // Produits en stock ayant au moins une réception datée qui périme avant la limite
        $produits = Produit::where('stock', '>', 0)->whereIn('id', DB::table('lignes_approvisionnement')
            ->join('approvisionnements', 'approvisionnements.id', '=', 'lignes_approvisionnement.approvisionnement_id')
            ->where('approvisionnements.boutique_id', boutique()->id)
            ->whereNotNull('date_peremption')->whereDate('date_peremption', '<=', $limite)
            ->select('produit_id'))->get();

        return $produits->flatMap(function (Produit $p) use ($limite) {
            $reste = (float) $p->stock;
            $lots = collect();
            $receptions = DB::table('lignes_approvisionnement')
                ->join('approvisionnements', 'approvisionnements.id', '=', 'lignes_approvisionnement.approvisionnement_id')
                ->where('lignes_approvisionnement.produit_id', $p->id)
                ->orderByDesc('approvisionnements.date_appro')->orderByDesc('lignes_approvisionnement.id')
                ->get(['lignes_approvisionnement.id', 'lignes_approvisionnement.quantite', 'lignes_approvisionnement.facteur',
                    'lignes_approvisionnement.date_peremption', 'approvisionnements.numero', 'approvisionnements.date_appro']);

            foreach ($receptions as $r) {
                if ($reste <= 0) {
                    break;
                }
                $dansLot = min($reste, (float) $r->quantite * ((float) $r->facteur ?: 1));
                $reste -= $dansLot;
                if ($r->date_peremption && substr($r->date_peremption, 0, 10) <= $limite) {
                    $date = \Carbon\Carbon::parse($r->date_peremption)->startOfDay();
                    $lots->push([
                        'produit' => $p, 'ligne_id' => $r->id, 'numero' => $r->numero,
                        'date_appro' => substr($r->date_appro, 0, 10), 'date_peremption' => $date,
                        'jours' => (int) now()->startOfDay()->diffInDays($date, false),
                        'quantite' => round($dansLot, 2), 'valeur' => (int) round($dansLot * $p->prix_achat),
                    ]);
                }
            }

            return $lots;
        })->sortBy('jours')->values();
    }
}
