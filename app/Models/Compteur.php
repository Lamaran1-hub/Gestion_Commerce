<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;

class Compteur extends Model
{
    use AppartientABoutique;

    public $timestamps = false;

    protected $fillable = ['boutique_id', 'type', 'annee', 'dernier_numero'];
}
