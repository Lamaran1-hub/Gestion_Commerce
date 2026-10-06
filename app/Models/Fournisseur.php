<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use App\Models\Concerns\Horodatage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fournisseur extends Model
{
    use AppartientABoutique, Horodatage, SoftDeletes;

    protected $fillable = ['nom', 'contact', 'telephone', 'email', 'adresse'];

    public function produits(): HasMany
    {
        return $this->hasMany(Produit::class);
    }

    public function approvisionnements(): HasMany
    {
        return $this->hasMany(Approvisionnement::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementFournisseur::class);
    }

    /** Ce que la boutique doit encore à ce fournisseur. */
    public function soldeDu(): int
    {
        return (int) $this->approvisionnements()->avecReste()->selectRaw('COALESCE(SUM(total - montant_retourne - montant_paye), 0) as du')->value('du');
    }

    /** Avoir disponible chez ce fournisseur (retours déjà payés qu'il n'a pas remboursés en argent). */
    public function avoirDisponible(): int
    {
        return max(0, -(int) $this->paiements()->where('mode', PaiementFournisseur::MODE_AVOIR)->sum('montant'));
    }

    public function retours(): HasMany
    {
        return $this->hasMany(RetourFournisseur::class);
    }
}
