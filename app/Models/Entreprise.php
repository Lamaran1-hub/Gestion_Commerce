<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Commerçant possédant plusieurs points de vente (réseau de boutiques). */
class Entreprise extends Model
{
    protected $fillable = ['nom'];

    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class)->orderBy('id');
    }
}
