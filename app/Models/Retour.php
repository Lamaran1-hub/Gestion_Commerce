<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Retour de marchandise (avoir) sur une vente. */
class Retour extends Model
{
    use AppartientABoutique;

    protected $fillable = ['boutique_id', 'numero', 'vente_id', 'montant', 'rembourse', 'mode_remboursement', 'echange_restant', 'echange_vente_id', 'motif', 'user_id'];

    protected $casts = ['montant' => 'integer', 'rembourse' => 'integer', 'echange_restant' => 'integer'];

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneRetour::class);
    }

    /** Vente payée avec le bon d'échange issu de ce retour. */
    public function venteEchange(): BelongsTo
    {
        return $this->belongsTo(Vente::class, 'echange_vente_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
