<?php

namespace App\Support;

use App\Models\Approvisionnement;
use App\Models\HistoriquePrix;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Surveillance des marges : un fournisseur augmente ses prix, le prix de vente ne bouge pas, la marge fond sans qu'on le voie.
 * Marge exprimée en % du prix de vente : (prix − coût) / prix.
 */
class Marges
{
    public static function seuil(): float
    {
        return (float) config('gestion.marge_alerte_pct', 10);
    }

    public static function taux(int $prix, int $cout): ?float
    {
        return $prix > 0 ? round(($prix - $cout) / $prix * 100, 1) : null;
    }

    /** Produits dont la marge est sous le seuil (prix d'achat connu). */
    public static function scopeFaibles(Builder $q): Builder
    {
        return $q->where('prix_vente', '>', 0)->where('prix_achat', '>', 0)
            ->whereRaw('(prix_vente - prix_achat) * 100 < ? * prix_vente', [self::seuil()]);
    }

    /** Prix qui redonne au produit la marge qu'il avait (même rapport prix / coût), arrondi aux 500 GNF supérieurs. */
    public static function prixSuggere(int $prixActuel, int $ancienCout, int $nouveauCout): int
    {
        if ($ancienCout <= 0 || $prixActuel <= 0) {
            return $prixActuel;
        }

        return (int) (ceil($nouveauCout * $prixActuel / $ancienCout / 500) * 500);
    }

    /**
     * Hausses de coût dues à une réception, avec la marge avant / après et un prix suggéré.
     *
     * @return Collection<int, array{produit:Produit, ancien:int, nouveau:int, prix:int, marge_avant:?float, marge:?float, suggere:int, sous_seuil:bool, deja_ajuste:bool}>
     */
    public static function apresReception(Approvisionnement $appro): Collection
    {
        $hausses = HistoriquePrix::where('origine', mb_substr('Réception '.$appro->numero, 0, 60))->where('champ', 'prix_achat')
            ->whereColumn('nouveau', '>', 'ancien')->where('ancien', '>', 0)->with('produit')->orderBy('id')->get()
            ->filter(fn ($h) => $h->produit)->keyBy('produit_id');   // la dernière hausse de chaque produit

        return $hausses->map(function (HistoriquePrix $h) {
            $p = $h->produit;
            // Prix de vente au moment de la réception (avant un éventuel ajustement depuis)
            $prixAlors = (int) (HistoriquePrix::where('produit_id', $p->id)->where('champ', 'prix_vente')->where('id', '>', $h->id)->orderBy('id')->value('ancien') ?? $p->prix_vente);

            return [
                'produit' => $p, 'ancien' => (int) $h->ancien, 'nouveau' => (int) $h->nouveau, 'prix' => (int) $p->prix_vente,
                'marge_avant' => self::taux($prixAlors, (int) $h->ancien), 'marge' => self::taux((int) $p->prix_vente, (int) $p->prix_achat),
                'suggere' => self::prixSuggere($prixAlors, (int) $h->ancien, (int) $h->nouveau),
                'sous_seuil' => (self::taux((int) $p->prix_vente, (int) $p->prix_achat) ?? 100) < self::seuil(),
                'deja_ajuste' => (int) $p->prix_vente !== $prixAlors,
            ];
        })->values();
    }
}
