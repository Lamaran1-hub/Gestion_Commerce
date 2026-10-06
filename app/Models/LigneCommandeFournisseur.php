<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LigneCommandeFournisseur extends Model
{
    public $timestamps = false;

    protected $table = 'lignes_commande_fournisseur';

    protected $fillable = ['commande_fournisseur_id', 'produit_id', 'designation', 'quantite', 'quantite_recue', 'prix_achat_estime'];

    protected $casts = ['quantite' => 'float', 'quantite_recue' => 'float', 'prix_achat_estime' => 'integer'];

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_fournisseur_id');
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    /** Ce qui n'est pas encore arrivé (unités de base). */
    public function reste(): float
    {
        return max(0.0, round($this->quantite - $this->quantite_recue, 2));
    }
}
