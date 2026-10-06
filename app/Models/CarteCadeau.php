<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Carte cadeau prépayée : son code fait foi, le solde se dépense à la caisse en une ou plusieurs fois. */
class CarteCadeau extends Model
{
    use AppartientABoutique;

    protected $table = 'cartes_cadeaux';

    protected $fillable = ['code', 'montant', 'solde', 'client_id', 'acheteur', 'beneficiaire', 'telephone', 'message', 'expire_le',
        'statut', 'user_id', 'annulee_le', 'motif_annulation'];

    protected $casts = ['montant' => 'integer', 'solde' => 'integer', 'expire_le' => 'date', 'annulee_le' => 'datetime'];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementCarteCadeau::class)->latest('date_mouvement')->latest('id');
    }

    public function estExpiree(): bool
    {
        return $this->expire_le !== null && $this->expire_le->endOfDay()->isPast();
    }

    /** active (utilisable), epuisee, expiree ou annulee */
    public function etat(): string
    {
        return match (true) {
            $this->statut === 'annulee' => 'annulee',
            $this->solde <= 0 => 'epuisee',
            $this->estExpiree() => 'expiree',
            default => 'active',
        };
    }

    /** Code masqué pour les reçus et l'historique : le code complet permet de dépenser la carte. */
    public function codeMasque(): string
    {
        return 'CC-••••-'.substr($this->code, -4);
    }

    /** Code lisible, groupé par 4 : CC-7KQ2-M9XA */
    public function codeLisible(): string
    {
        return 'CC-'.substr($this->code, 0, 4).'-'.substr($this->code, 4);
    }

    public function scopeEnCirculation($q)
    {
        return $q->where('statut', 'active')->where('solde', '>', 0)
            ->where(fn ($q) => $q->whereNull('expire_le')->orWhereDate('expire_le', '>=', now()->toDateString()));
    }
}
