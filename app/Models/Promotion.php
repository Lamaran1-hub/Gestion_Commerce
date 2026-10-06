<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Promotion extends Model
{
    use AppartientABoutique;

    protected $fillable = ['nom', 'type', 'valeur', 'produit_id', 'categorie_id', 'debut', 'fin', 'actif', 'user_id'];

    protected $casts = ['valeur' => 'float', 'debut' => 'date', 'fin' => 'date', 'actif' => 'boolean'];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function scopeEnCours(Builder $q): Builder
    {
        $jour = now()->toDateString();

        return $q->where('actif', true)->whereDate('debut', '<=', $jour)->whereDate('fin', '>=', $jour);
    }

    public function concerne(Produit $p): bool
    {
        return $this->produit_id ? $this->produit_id === $p->id
            : ($this->categorie_id ? $this->categorie_id === $p->categorie_id : true);
    }

    public function portee(): string
    {
        return $this->produit ? $this->produit->designation : ($this->categorie ? 'Catégorie '.$this->categorie->nom : 'Toute la boutique');
    }

    public function libelleReduction(): string
    {
        return $this->type === 'pourcentage' ? '−'.rtrim(rtrim(number_format($this->valeur, 2, ',', ''), '0'), ',').' %' : gnf((int) $this->valeur).' l\'unité';
    }

    public function etat(): string
    {
        return match (true) {
            ! $this->actif => 'Arrêtée',
            $this->fin->endOfDay()->isPast() => 'Terminée',
            $this->debut->isFuture() => 'À venir',
            default => 'En cours',
        };
    }
}
