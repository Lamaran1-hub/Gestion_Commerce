<?php

namespace App\Support;

/**
 * Codes-barres Code 128 (lus par toutes les douchettes) rendus en SVG, sans dépendance.
 * Jeu C (paires de chiffres) pour les codes numériques pairs comme les EAN, jeu B sinon.
 */
class CodeBarre
{
    /** Largeurs barre/espace de chaque symbole (0 à 106, 106 = stop). */
    public const MOTIFS = [
        '212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
        '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
        '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
        '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
        '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
        '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
        '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
        '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
        '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
        '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
        '114131', '311141', '411131', '211412', '211214', '211232', '2331112',
    ];

    /** Valeurs des symboles (départ, données, contrôle, stop). */
    public static function symboles(string $code): array
    {
        if ($code === '' || preg_match('/[^\x20-\x7E]/', $code)) {
            throw new \InvalidArgumentException('Code-barres : caractères imprimables uniquement.');
        }
        if (ctype_digit($code) && strlen($code) % 2 === 0) {
            $valeurs = [105];
            foreach (str_split($code, 2) as $paire) {
                $valeurs[] = (int) $paire;
            }
        } else {
            $valeurs = [104];
            foreach (str_split($code) as $c) {
                $valeurs[] = ord($c) - 32;
            }
        }
        $somme = $valeurs[0];
        foreach (array_slice($valeurs, 1) as $i => $v) {
            $somme += ($i + 1) * $v;
        }
        $valeurs[] = $somme % 103;
        $valeurs[] = 106;

        return $valeurs;
    }

    public static function svg(string $code, int $hauteur = 40, float $module = 1.0): string
    {
        $x = 10 * $module; // zone de silence
        $barres = '';
        foreach (self::symboles($code) as $v) {
            foreach (str_split(self::MOTIFS[$v]) as $i => $largeur) {
                $l = (int) $largeur * $module;
                if ($i % 2 === 0) {
                    $barres .= '<rect x="'.$x.'" y="0" width="'.$l.'" height="'.$hauteur.'"/>';
                }
                $x += $l;
            }
        }
        $x += 10 * $module;

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$x.' '.$hauteur.'" preserveAspectRatio="none" role="img" aria-label="'.e($code).'">'
            .'<rect width="100%" height="100%" fill="#fff"/><g fill="#000">'.$barres.'</g></svg>';
    }

    /** EAN-13 « magasin » (préfixe 2, réservé à l'usage interne par GS1) pour un produit sans code. */
    public static function ean13Interne(int $id): string
    {
        $base = '2'.str_pad((string) $id, 11, '0', STR_PAD_LEFT);

        return $base.self::cleEan($base);
    }

    public static function cleEan(string $douze): int
    {
        $s = 0;
        foreach (str_split($douze) as $i => $c) {
            $s += (int) $c * ($i % 2 ? 3 : 1);
        }

        return (10 - $s % 10) % 10;
    }
}
