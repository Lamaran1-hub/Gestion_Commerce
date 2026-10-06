<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Marchandise renvoyée au fournisseur, sur une réception. */
class RetourFournisseur extends Model
{
    use AppartientABoutique;

    protected $table = 'retours_fournisseur';

    protected $fillable = ['boutique_id', 'numero', 'approvisionnement_id', 'fournisseur_id', 'montant', 'deduit', 'rembourse', 'mode_remboursement', 'motif', 'note', 'user_id'];

    protected $casts = ['montant' => 'integer', 'deduit' => 'integer', 'rembourse' => 'integer'];

    public function approvisionnement(): BelongsTo
    {
        return $this->belongsTo(Approvisionnement::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneRetourFournisseur::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** « déduit de la dette », « remboursé 50 000 (Orange Money) », « avoir de 50 000 chez le fournisseur »… */
    public function libelleReglement(): string
    {
        $parts = [];
        if ($this->deduit) {
            $parts[] = 'déduit de la dette : '.gnf($this->deduit);
        }
        if ($this->rembourse) {
            $parts[] = $this->mode_remboursement === PaiementFournisseur::MODE_AVOIR
                ? 'avoir chez le fournisseur : '.gnf($this->rembourse)
                : 'remboursé '.gnf($this->rembourse).' ('.libelle_mode($this->mode_remboursement).')';
        }

        return $parts ? implode(' · ', $parts) : '—';
    }
}
