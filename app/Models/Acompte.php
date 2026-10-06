<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Avance versée par un client sur un devis ou une commande (négative = remboursement). */
class Acompte extends Model
{
    use AppartientABoutique;

    protected $fillable = ['boutique_id', 'devis_id', 'montant', 'mode', 'reference', 'date_versement', 'user_id'];

    protected $casts = ['montant' => 'integer', 'date_versement' => 'datetime'];

    public function devis(): BelongsTo
    {
        return $this->belongsTo(Devis::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
