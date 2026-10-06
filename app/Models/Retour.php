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

    protected $fillable = ['boutique_id', 'numero', 'vente_id', 'montant', 'rembourse', 'mode_remboursement', 'motif', 'user_id'];

    protected $casts = ['montant' => 'integer', 'rembourse' => 'integer'];

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneRetour::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
