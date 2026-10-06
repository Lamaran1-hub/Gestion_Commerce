<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneDevis extends Model
{
    public $timestamps = false;

    protected $table = 'lignes_devis';

    protected $fillable = ['devis_id', 'produit_id', 'designation', 'quantite', 'facteur', 'unite', 'prix_unitaire', 'total', 'taux_tva'];

    protected $casts = ['quantite' => 'float', 'facteur' => 'float', 'prix_unitaire' => 'integer', 'total' => 'integer', 'taux_tva' => 'float'];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }
}
