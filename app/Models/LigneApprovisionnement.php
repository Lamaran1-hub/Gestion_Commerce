<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneApprovisionnement extends Model
{
    protected $table = 'lignes_approvisionnement';

    public $timestamps = false;

    protected $fillable = ['produit_id', 'designation', 'quantite', 'quantite_retournee', 'facteur', 'unite', 'prix_achat_unitaire', 'total', 'date_peremption'];

    /** Quantité encore renvoyable au fournisseur (dans l'unité reçue : unité ou carton). */
    public function quantiteRetournable(): float
    {
        return max(0.0, round($this->quantite - $this->quantite_retournee, 2));
    }

    public function produit(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    protected $casts = ['quantite' => 'float', 'quantite_retournee' => 'float', 'facteur' => 'float', 'prix_achat_unitaire' => 'integer', 'total' => 'integer', 'date_peremption' => 'date'];
}
