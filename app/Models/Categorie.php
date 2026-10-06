<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categorie extends Model
{
    use AppartientABoutique;

    protected $table = 'categories';

    protected $fillable = ['nom'];

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }
}
