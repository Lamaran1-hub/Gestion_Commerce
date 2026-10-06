<?php

namespace App\Services;

use App\Models\Compteur;
use Illuminate\Support\Facades\DB;

/**
 * Numérotation continue par boutique : V-2026-00001, AP-2026-00001, CLI-00001.
 * Le verrou empêche deux caisses d'obtenir le même numéro.
 */
class NumeroService
{
    public function suivant(string $type, string $prefixe, bool $annuel = true): string
    {
        return DB::transaction(function () use ($type, $prefixe, $annuel) {
            $annee = $annuel ? (int) now()->year : 0;
            $compteur = Compteur::where(['type' => $type, 'annee' => $annee])->lockForUpdate()->first()
                ?? Compteur::create(['type' => $type, 'annee' => $annee, 'dernier_numero' => 0]);

            $compteur->increment('dernier_numero');

            return $annuel
                ? sprintf('%s-%d-%05d', $prefixe, $annee, $compteur->dernier_numero)
                : sprintf('%s-%05d', $prefixe, $compteur->dernier_numero);
        });
    }
}
