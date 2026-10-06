<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Commande passée à un fournisseur, suivie jusqu'à la réception. */
class CommandeFournisseur extends Model
{
    use AppartientABoutique;

    protected $table = 'commandes_fournisseur';

    public const ETATS = [
        'envoyee' => ['Envoyée', 'etat-alerte'],
        'partielle' => ['Reçue en partie', 'etat-alerte'],
        'recue' => ['Reçue', 'etat-ok'],
        'soldee' => ['Soldée (reliquat abandonné)', 'etat-neutre'],
        'annulee' => ['Annulée', 'etat-neutre'],
    ];

    protected $fillable = ['boutique_id', 'numero', 'fournisseur_id', 'date_commande', 'livraison_prevue_le', 'statut', 'total_estime', 'note', 'user_id'];

    protected $casts = ['date_commande' => 'date', 'livraison_prevue_le' => 'date', 'total_estime' => 'integer'];

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommandeFournisseur::class);
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Approvisionnement::class)->latest('date_appro');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Commandes dont on attend encore de la marchandise. */
    public function scopeEnAttente(Builder $q): Builder
    {
        return $q->whereIn('statut', ['envoyee', 'partielle']);
    }

    public function estEnAttente(): bool
    {
        return in_array($this->statut, ['envoyee', 'partielle'], true);
    }

    public function enRetard(): bool
    {
        return $this->estEnAttente() && $this->livraison_prevue_le && $this->livraison_prevue_le->endOfDay()->isPast();
    }

    public function libelleEtat(): string
    {
        return self::ETATS[$this->statut][0] ?? $this->statut;
    }

    public function classeEtat(): string
    {
        return $this->enRetard() ? 'etat-rupture' : (self::ETATS[$this->statut][1] ?? 'etat-neutre');
    }
}
