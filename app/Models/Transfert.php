<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Transfert de marchandise entre deux boutiques d'un même réseau (visible des deux côtés). */
class Transfert extends Model
{
    protected $fillable = ['entreprise_id', 'numero', 'boutique_source_id', 'boutique_destination_id', 'statut', 'valeur', 'note',
        'envoye_par', 'envoye_le', 'recu_par', 'recu_le', 'note_reception'];

    protected $casts = ['envoye_le' => 'datetime', 'recu_le' => 'datetime', 'valeur' => 'integer'];

    public const STATUTS = ['envoye' => 'En route', 'recu' => 'Reçu', 'annule' => 'Annulé'];

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneTransfert::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_source_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(Boutique::class, 'boutique_destination_id');
    }

    public function expediteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }

    public function receptionnaire(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recu_par');
    }

    /** Transferts qui concernent une boutique (envoyés ou reçus). */
    public function scopeDe(Builder $q, int $boutiqueId): Builder
    {
        return $q->where(fn ($s) => $s->where('boutique_source_id', $boutiqueId)->orWhere('boutique_destination_id', $boutiqueId));
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }
}
