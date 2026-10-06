<?php

namespace App\Support;

/** Conversion d'un entier en toutes lettres (français), pour les factures. */
class NombreEnLettres
{
    private const UNITES = ['zéro', 'un', 'deux', 'trois', 'quatre', 'cinq', 'six', 'sept', 'huit', 'neuf', 'dix',
        'onze', 'douze', 'treize', 'quatorze', 'quinze', 'seize', 'dix-sept', 'dix-huit', 'dix-neuf'];

    private const DIZAINES = [2 => 'vingt', 3 => 'trente', 4 => 'quarante', 5 => 'cinquante', 6 => 'soixante', 8 => 'quatre-vingt'];

    public static function convertir(int $n): string
    {
        if ($n < 0) {
            return 'moins '.self::convertir(-$n);
        }
        if ($n === 0) {
            return 'zéro';
        }

        $parts = [];
        foreach ([1_000_000_000 => 'milliard', 1_000_000 => 'million'] as $valeur => $mot) {
            if ($n >= $valeur) {
                $q = intdiv($n, $valeur);
                $parts[] = self::moinsDeMille($q).' '.$mot.($q > 1 ? 's' : '');
                $n %= $valeur;
            }
        }
        if ($n >= 1000) {
            $q = intdiv($n, 1000);
            $parts[] = $q === 1 ? 'mille' : self::moinsDeMille($q, false).' mille';
            $n %= 1000;
        }
        if ($n > 0) {
            $parts[] = self::moinsDeMille($n);
        }

        return implode(' ', $parts);
    }

    private static function moinsDeMille(int $n, bool $final = true): string
    {
        $c = intdiv($n, 100);
        $r = $n % 100;
        $texte = '';
        if ($c > 0) {
            $texte = $c === 1 ? 'cent' : self::UNITES[$c].' cent';
            if ($r === 0 && $c > 1 && $final) {
                $texte .= 's';
            }
            if ($r > 0) {
                $texte .= ' ';
            }
        }

        return $texte.($r > 0 ? self::moinsDeCent($r, $final) : '');
    }

    private static function moinsDeCent(int $n, bool $final): string
    {
        if ($n < 20) {
            return self::UNITES[$n];
        }
        $d = intdiv($n, 10);
        $u = $n % 10;
        if ($d === 7 || $d === 9) {
            $base = $d === 7 ? 'soixante' : 'quatre-vingt';
            $reste = $n - ($d === 7 ? 60 : 80);

            return $base.(($d === 7 && $reste === 11) ? ' et ' : '-').self::UNITES[$reste];
        }
        if ($u === 0) {
            return self::DIZAINES[$d].($d === 8 && $final ? 's' : '');
        }

        return self::DIZAINES[$d].($u === 1 && $d !== 8 ? ' et ' : '-').self::UNITES[$u];
    }
}
