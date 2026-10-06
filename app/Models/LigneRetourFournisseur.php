<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneRetourFournisseur extends Model
{
    public $timestamps = false;

    protected $table = 'lignes_retour_fournisseur';

    protected $fillable = ['retour_fournisseur_id', 'ligne_approvisionnement_id', 'produit_id', 'designation', 'quantite', 'facteur', 'unite', 'prix_achat_unitaire', 'total'];

    protected $casts = ['quantite' => 'float', 'facteur' => 'float', 'prix_achat_unitaire' => 'integer', 'total' => 'integer'];
}
