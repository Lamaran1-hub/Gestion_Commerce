<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LigneTransfert extends Model
{
    protected $table = 'lignes_transfert';

    public $timestamps = false;

    protected $fillable = ['produit_source_id', 'produit_destination_id', 'designation', 'code_barre', 'unite', 'quantite', 'quantite_recue', 'cout_unitaire'];

    protected $casts = ['quantite' => 'float', 'quantite_recue' => 'float', 'cout_unitaire' => 'integer'];

    public function ecart(): float
    {
        return $this->quantite_recue === null ? 0.0 : round($this->quantite - $this->quantite_recue, 2);
    }
}
