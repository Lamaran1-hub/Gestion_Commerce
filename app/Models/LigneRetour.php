<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneRetour extends Model
{
    public $timestamps = false;

    protected $table = 'lignes_retour';

    protected $fillable = ['retour_id', 'ligne_vente_id', 'produit_id', 'designation', 'quantite', 'facteur', 'unite', 'prix_unitaire', 'total'];

    protected $casts = ['quantite' => 'float', 'facteur' => 'float', 'prix_unitaire' => 'integer', 'total' => 'integer'];
}
