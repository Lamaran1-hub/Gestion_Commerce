<?php

namespace App\Services;

use App\Models\Boutique;
use App\Models\HistoriquePrix;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Models\Promotion;
use App\Support\Tva;
use Illuminate\Support\Facades\DB;

/**
 * Passage des prix hors taxe aux prix TVA comprise (ou l'inverse) sans changer ce que paient les clients :
 * prix de vente, de gros, par conditionnement et promotions à prix fixe sont multipliés (ou divisés) par 1 + taux.
 * Les produits exonérés ne changent pas. Les ventes déjà faites ne sont jamais touchées.
 */
class ConversionPrixTva
{
    /** @return int nombre de produits ajustés */
    public function convertir(Boutique $boutique, bool $versTtc): int
    {
        $origine = $versTtc ? 'Passage aux prix TTC' : 'Passage aux prix HT';

        return HistoriquePrix::depuis($origine, fn () => DB::transaction(function () use ($boutique, $versTtc) {
            $ajuster = fn (?int $prix, float $taux) => $prix === null || $taux <= 0 ? $prix
                : (int) round($versTtc ? $prix * (1 + $taux / 100) : $prix / (1 + $taux / 100));
            $tauxDe = fn (Produit $p) => $p->taux_tva !== null ? (float) $p->taux_tva : (float) $boutique->tva_taux;

            $nb = 0;
            Produit::query()->chunkById(200, function ($produits) use ($ajuster, $tauxDe, &$nb) {
                foreach ($produits as $p) {
                    $taux = $tauxDe($p);
                    if ($taux <= 0) {
                        continue;   // exonéré : même prix dans les deux cas
                    }
                    $p->forceFill([
                        'prix_vente' => $ajuster($p->prix_vente, $taux),
                        'prix_gros' => $ajuster($p->prix_gros, $taux),
                        'prix_conditionnement' => $ajuster($p->prix_conditionnement, $taux),
                    ])->save();   // l'historique des prix note chaque ajustement
                    $nb++;
                }
            });
            // Promotions à prix fixe encore en cours ou à venir (un pourcentage, lui, ne change pas)
            Promotion::where('type', 'prix')->whereDate('fin', '>=', now()->toDateString())->with('produit')->get()
                ->each(function (Promotion $promo) use ($ajuster, $tauxDe, $boutique) {
                    $taux = $promo->produit ? $tauxDe($promo->produit) : (float) $boutique->tva_taux;
                    $promo->update(['valeur' => $ajuster((int) round($promo->valeur), $taux)]);
                });
            JournalActivite::noter('parametres', ($versTtc ? 'Passage aux prix TVA comprise' : 'Passage aux prix hors taxe')
                ." : {$nb} prix de vente ajustés (taux ".Tva::libelle((float) $boutique->tva_taux).'), les clients paient le même montant');

            return $nb;
        }));
    }
}
