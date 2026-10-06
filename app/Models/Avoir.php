<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mouvement d'avoir client : + crédité lors d'un retour, − utilisé en paiement à la caisse. */
class Avoir extends Model
{
    use AppartientABoutique;

    public const UPDATED_AT = null;

    protected $fillable = ['client_id', 'vente_id', 'retour_id', 'montant', 'motif', 'user_id'];

    protected $casts = ['montant' => 'integer'];

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function retour(): BelongsTo
    {
        return $this->belongsTo(Retour::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
