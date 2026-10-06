<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = ['nom', 'prix_mensuel', 'max_utilisateurs', 'max_produits', 'max_boutiques', 'fonctions', 'description', 'actif'];

    protected $casts = ['actif' => 'boolean', 'prix_mensuel' => 'integer', 'fonctions' => 'array'];

    /** Fonction incluse dans la formule ? (liste vide en base = aucune ; null = toutes) */
    public function inclut(string $fonction): bool
    {
        return $this->fonctions === null || in_array($fonction, $this->fonctions, true);
    }

    public function boutiques(): HasMany
    {
        return $this->hasMany(Boutique::class);
    }
}
