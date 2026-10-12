<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;

/** Monnaie mise dans le tiroir d'un caissier en début de journée. */
class FondCaisse extends Model
{
    use AppartientABoutique;

    protected $table = 'fonds_caisse';

    protected $fillable = ['boutique_id', 'user_id', 'jour', 'montant'];

    protected $casts = ['jour' => 'date', 'montant' => 'integer'];
}
