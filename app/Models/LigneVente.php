<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneVente extends Model
{
    protected $table = 'lignes_vente';

    public $timestamps = false;

    protected $fillable = ['produit_id', 'designation', 'quantite', 'quantite_retournee', 'facteur', 'unite', 'prix_unitaire', 'prix_achat', 'total', 'taux_tva', 'garantie_mois'];

    protected $casts = ['quantite' => 'float', 'facteur' => 'float', 'quantite_retournee' => 'float', 'prix_unitaire' => 'integer', 'prix_achat' => 'integer', 'total' => 'integer', 'taux_tva' => 'float', 'garantie_mois' => 'integer'];

    public function numerosSerie(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(NumeroSerie::class)->whereNull('retour_id')->orderBy('id');   // encore chez le client
    }

    /** Fin de garantie (null = pas de garantie). */
    public function garantieJusquau(): ?\Carbon\Carbon
    {
        return $this->garantie_mois ? $this->vente?->date_vente?->copy()->addMonthsNoOverflow($this->garantie_mois)->startOfDay() : null;
    }

    /** Nombre de numéros de série attendus (une unité = un numéro ; 1 carton de 12 = 12 numéros). */
    public function nombreSeriesAttendues(): int
    {
        return $this->produit?->suivi_serie ? (int) round($this->quantite * ($this->facteur ?: 1)) : 0;   // quantité nette des retours
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }
}
