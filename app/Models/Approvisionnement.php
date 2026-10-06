<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Approvisionnement extends Model
{
    use AppartientABoutique;

    protected $fillable = ['numero', 'fournisseur_id', 'commande_fournisseur_id', 'date_appro', 'total', 'montant_paye', 'montant_retourne', 'echeance', 'note', 'user_id'];

    protected $casts = ['date_appro' => 'date', 'total' => 'integer', 'montant_paye' => 'integer', 'montant_retourne' => 'integer', 'echeance' => 'date'];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(CommandeFournisseur::class, 'commande_fournisseur_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneApprovisionnement::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(PaiementFournisseur::class)->oldest('date_paiement');
    }

    public function retours(): HasMany
    {
        return $this->hasMany(RetourFournisseur::class)->latest('id');
    }

    /** Ce que la boutique doit vraiment pour cette réception : marchandise renvoyée au fournisseur déduite. */
    public function netAPayer(): int
    {
        return max(0, $this->total - $this->montant_retourne);
    }

    public function resteAPayer(): int
    {
        return max(0, $this->netAPayer() - $this->montant_paye);
    }

    /** Échéance dépassée avec un reste à payer. */
    public function enRetard(): bool
    {
        return $this->resteAPayer() > 0 && $this->echeance && $this->echeance->endOfDay()->isPast();
    }

    public function scopeAvecReste(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->whereRaw('montant_paye + montant_retourne < total');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
