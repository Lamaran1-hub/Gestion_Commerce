<?php

namespace App\Support;

use App\Models\Boutique;
use App\Models\Produit;

/**
 * TVA multi-taux.
 * - Taux d'un produit : le sien s'il en a un (0 = exonéré), sinon le taux normal de la boutique ;
 *   0 pour tout le monde si la boutique n'est pas assujettie.
 * - La remise globale du ticket est répartie sur les lignes au prorata de leur montant,
 *   puis la TVA est calculée par taux (ventilation), comme sur une facture conforme.
 * - Deux façons de saisir les prix (réglage de la boutique, mémorisé sur chaque vente et devis) :
 *   hors taxe (la TVA s'ajoute au prix) ou TTC (la TVA est comprise dans le prix affiché : on l'en extrait,
 *   le client paie exactement le prix de l'étiquette).
 */
class Tva
{
    public static function tauxProduit(Produit $p, ?Boutique $b = null): float
    {
        $b ??= boutique();
        if (! $b?->tva_active) {
            return 0.0;
        }

        return $p->taux_tva !== null ? (float) $p->taux_tva : (float) $b->tva_taux;
    }

    /**
     * @param  iterable<array{total:int|float, taux:float|int|string|null}>  $lignes  montants des lignes (avant remise globale), HT ou TTC selon $prixTtc
     * @param  int  $totalHt  somme des lignes (avant remise globale)
     * @return array{tva:int, ventilation:array<string, array{taux:float, base:int, tva:int}>, principal:float}  base : toujours hors taxe
     */
    public static function ventiler(iterable $lignes, int $totalHt, int $remise, bool $prixTtc = false): array
    {
        $parTaux = [];
        foreach ($lignes as $l) {
            $taux = (float) ($l['taux'] ?? 0);
            $cle = number_format($taux, 2, '.', '');
            $part = $totalHt > 0 ? $l['total'] * $remise / $totalHt : 0;
            $parTaux[$cle] = ($parTaux[$cle] ?? 0) + $l['total'] - $part;
        }
        // Un taux dont toutes les lignes ont été retournées n'apparaît plus (sauf s'il est le seul)
        if (count($parTaux) > 1) {
            $parTaux = array_filter($parTaux, fn ($base) => abs($base) >= 0.5) ?: $parTaux;
        }
        krsort($parTaux);
        $ventilation = [];
        $tva = 0;
        foreach ($parTaux as $cle => $base) {
            // Prix TTC : la TVA est comprise dans la base, on l'extrait ; sinon elle s'y ajoute
            $montant = $prixTtc ? (int) round($base * (float) $cle / (100 + (float) $cle)) : (int) round($base * (float) $cle / 100);
            $ventilation[$cle] = ['taux' => (float) $cle, 'base' => (int) round($base) - ($prixTtc ? $montant : 0), 'tva' => $montant];
            $tva += $montant;
        }
        // Taux « principal » (celui qui porte la plus grosse base), gardé pour l'affichage simple
        $principal = $ventilation ? collect($ventilation)->sortByDesc('base')->first()['taux'] : 0.0;

        return ['tva' => $tva, 'ventilation' => $ventilation, 'principal' => $principal];
    }

    /**
     * Totaux d'une vente ou d'un devis.
     * Toujours : total_ttc = total_ht − remise + tva (total_ht = somme des lignes hors taxe, avant remise).
     * Prix TTC : total_ttc = somme des lignes − remise, exactement ce que le client voit sur les étiquettes.
     *
     * @param  iterable<array{total:int|float, taux:float|int|string|null}>  $lignes
     * @return array{total_ht:int, tva:int, total_ttc:int, principal:float}
     */
    public static function totaux(iterable $lignes, int $sommeLignes, int $remise, bool $prixTtc): array
    {
        $v = self::ventiler($lignes, $sommeLignes, $remise, $prixTtc);

        return $prixTtc
            ? ['total_ht' => $sommeLignes - $v['tva'], 'tva' => $v['tva'], 'total_ttc' => $sommeLignes - $remise, 'principal' => $v['principal']]
            : ['total_ht' => $sommeLignes, 'tva' => $v['tva'], 'total_ttc' => $sommeLignes - $remise + $v['tva'], 'principal' => $v['principal']];
    }

    /**
     * Expression SQL d'un montant de ligne de vente ramené hors taxe (pour les marges et le chiffre d'affaires HT).
     * Les lignes d'une vente en prix TTC contiennent la TVA : on la retire avec le taux de la ligne.
     */
    public static function sqlHt(string $colonne, string $ventes = 'ventes', string $lignes = 'lignes_vente'): string
    {
        return "(CASE WHEN {$ventes}.prix_ttc = 1 THEN {$colonne} * 100.0 / (100 + COALESCE({$lignes}.taux_tva, 0)) ELSE {$colonne} END)";
    }

    /**
     * Prix réellement payé par le client pour un prix catalogue (prix de vente, de gros, promotion) :
     * boutique en prix hors taxe qui facture la TVA → TVA ajoutée ; prix TTC ou pas de TVA → prix inchangé.
     * À utiliser partout où un prix est montré au client (vitrine, étiquettes, écran client, messages).
     */
    public static function prixClient(?int $prix, Produit $p, ?Boutique $b = null): ?int
    {
        $b ??= boutique();
        if ($prix === null || ! $b?->tva_active || $b->prix_ttc) {
            return $prix;
        }

        return (int) round($prix * (1 + self::tauxProduit($p, $b) / 100));
    }

    /** Montant hors taxe d'un prix de ligne (prix TTC : TVA retirée). */
    public static function horsTaxe(float|int $montant, float|int|null $taux, bool $prixTtc): float
    {
        return $prixTtc ? $montant * 100 / (100 + (float) $taux) : (float) $montant;
    }

    public static function libelle(float $taux): string
    {
        return $taux == 0 ? 'Exonéré' : rtrim(rtrim(number_format($taux, 2, ',', ''), '0'), ',').' %';
    }
}
