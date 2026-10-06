<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MouvementStock extends Model
{
    use AppartientABoutique;

    public const UPDATED_AT = null;

    protected $table = 'mouvements_stock';

    protected $fillable = ['produit_id', 'type', 'quantite', 'stock_apres', 'cout_unitaire', 'reference_type', 'reference_id', 'motif', 'user_id'];

    protected $casts = ['quantite' => 'float', 'stock_apres' => 'float', 'cout_unitaire' => 'integer'];

    public const LIBELLES = [
        'approvisionnement' => 'Approvisionnement',
        'vente' => 'Vente',
        'annulation_vente' => 'Annulation de vente',
        'retour_client' => 'Retour client',
        'retour_fournisseur' => 'Retour au fournisseur',
        'ajustement' => 'Ajustement',
        'peremption' => 'Retrait (périmé)',
        'transfert_sortie' => 'Transfert envoyé',
        'transfert_entree' => 'Transfert reçu',
        'transfert_annule' => 'Transfert annulé (retour en stock)',
        'perte_transit' => 'Perte en transit',
        'stock_initial' => 'Stock initial',
    ];

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class)->withTrashed();
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function libelle(): string
    {
        return self::LIBELLES[$this->type] ?? $this->type;
    }
}
