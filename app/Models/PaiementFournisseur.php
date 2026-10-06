<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Règlement d'une dette fournisseur (sur une réception de marchandise). */
class PaiementFournisseur extends Model
{
    use AppartientABoutique;

    /** Avoir accordé par le fournisseur après un retour : rendu (montant négatif), puis utilisé pour régler (positif). Aucun argent ne bouge. */
    public const MODE_AVOIR = 'avoir_fournisseur';

    protected $table = 'paiements_fournisseur';

    protected $fillable = ['boutique_id', 'fournisseur_id', 'approvisionnement_id', 'montant', 'mode', 'reference', 'date_paiement', 'user_id'];

    protected $casts = ['montant' => 'integer', 'date_paiement' => 'datetime'];

    public function approvisionnement(): BelongsTo
    {
        return $this->belongsTo(Approvisionnement::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleMode(): string
    {
        return libelle_mode($this->mode);
    }
}
