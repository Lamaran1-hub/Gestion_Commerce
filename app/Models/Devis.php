<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Devis / facture proforma. */
class Devis extends Model
{
    use AppartientABoutique;

    protected $table = 'devis';

    protected $fillable = [
        'boutique_id', 'numero', 'client_id', 'client_nom', 'date_devis', 'valable_jusqu_au', 'total_ht', 'remise',
        'tva_taux', 'total_tva', 'total_ttc', 'acompte', 'statut', 'vente_id', 'note', 'user_id', 'origine', 'client_telephone', 'prix_ttc',
    ];

    protected $casts = [
        'date_devis' => 'date', 'valable_jusqu_au' => 'date', 'total_ht' => 'integer', 'remise' => 'integer',
        'total_tva' => 'integer', 'total_ttc' => 'integer', 'acompte' => 'integer', 'prix_ttc' => 'boolean',
    ];

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class);
    }

    /** Sous-total affiché (somme des lignes, avant remise) : hors taxe, ou TVA comprise pour un document en prix TTC. */
    public function sousTotal(): int
    {
        return $this->prix_ttc ? (int) $this->total_ht + (int) $this->total_tva : (int) $this->total_ht;
    }

    /** Libellé d'une ligne de TVA : en prix TTC, la TVA est comprise (« dont »), elle ne s'ajoute pas. */
    public function libelleTva(float $taux): string
    {
        return ($this->prix_ttc ? 'dont TVA ' : 'TVA ').\App\Support\Tva::libelle($taux);
    }

    /** Ventilation de la TVA par taux (base après remise, montant). */
    public function ventilationTva(): array
    {
        return \App\Support\Tva::ventiler($this->lignes->map(fn ($l) => ['total' => $l->total, 'taux' => $l->taux_tva ?? $this->tva_taux]),
            (int) $this->lignes->sum('total'), (int) $this->remise, (bool) $this->prix_ttc)['ventilation'];
    }

    public function acomptes(): HasMany
    {
        return $this->hasMany(Acompte::class)->oldest('date_versement')->oldest('id');
    }

    /** Ce que le client doit encore à la livraison. */
    public function resteAPayer(): int
    {
        return max(0, $this->total_ttc - $this->acompte);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function vente(): BelongsTo
    {
        return $this->belongsTo(Vente::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Commandes reçues sur la vitrine en ligne, pas encore traitées (ni vendues, ni annulées, ni expirées). */
    public function scopeCommandesATraiter($q)
    {
        return $q->where('origine', 'vitrine')->where('statut', 'en_cours')->whereDate('valable_jusqu_au', '>=', now()->toDateString());
    }

    public function estExpire(): bool
    {
        return $this->statut === 'en_cours' && $this->valable_jusqu_au->endOfDay()->isPast();
    }

    /** Statut affiché : l'expiration se déduit de la date de validité. */
    public function etat(): string
    {
        return $this->estExpire() ? 'expire' : $this->statut;
    }

    public function libelleEtat(): string
    {
        return ['en_cours' => 'En cours', 'converti' => 'Transformé en vente', 'annule' => 'Annulé', 'expire' => 'Expiré'][$this->etat()];
    }

    public function classeEtat(): string
    {
        return ['en_cours' => 'etat-alerte', 'converti' => 'etat-ok', 'annule' => 'etat-neutre', 'expire' => 'etat-rupture'][$this->etat()];
    }

    public function nomClient(): string
    {
        return $this->client?->nomComplet() ?? ($this->client_nom ?: 'Client comptoir');
    }
}
