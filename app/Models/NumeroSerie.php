<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Numéro de série (IMEI, n° de fabrication) d'un article vendu. */
class NumeroSerie extends Model
{
    use AppartientABoutique;

    protected $table = 'numeros_serie';

    protected $fillable = ['boutique_id', 'vente_id', 'ligne_vente_id', 'produit_id', 'retour_id', 'numero'];

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(LigneVente::class, 'ligne_vente_id');
    }

    public function retour(): BelongsTo
    {
        return $this->belongsTo(Retour::class);
    }

    /** Numéros encore chez le client (pas rapportés). */
    public function scopeChezLeClient($q)
    {
        return $q->whereNull('retour_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    /** Normalisation : majuscules, sans espaces ni tirets (un IMEI se tape de plusieurs façons). */
    public static function normaliser(string $numero): string
    {
        return strtoupper(preg_replace('/[\s\-\.]+/', '', trim($numero)));
    }
}
