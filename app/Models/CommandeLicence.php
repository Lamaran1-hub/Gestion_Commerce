<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Achat de licence en ligne : de la demande au paiement vérifié chez le prestataire. */
class CommandeLicence extends Model
{
    public const STATUTS = [
        'en_attente' => ['En attente de paiement', 'etat-alerte'],
        'a_valider' => ['Payée — à valider', 'etat-alerte'],
        'payee' => ['Payée — licence activée', 'etat-ok'],
        'echouee' => ['Échouée', 'etat-rupture'],
        'annulee' => ['Annulée', 'etat-neutre'],
        'expiree' => ['Expirée', 'etat-neutre'],
        'anomalie' => ['Anomalie — à vérifier', 'etat-rupture'],
    ];

    /** Statuts définitifs : plus aucune transition possible. */
    public const FINAUX = ['payee', 'echouee', 'annulee', 'expiree'];

    protected $table = 'commandes_licence';

    // Valeurs par défaut aussi en mémoire (pas seulement en base) : les règles s'appuient dessus dès la création
    protected $attributes = ['statut' => 'en_attente', 'environnement' => 'sandbox', 'devise' => 'GNF', 'fournisseur' => 'djomy'];

    protected $fillable = [
        'reference', 'boutique_id', 'plan_id', 'mois', 'montant', 'devise', 'fournisseur', 'environnement', 'moyen', 'moyen_utilise', 'statut', 'numero_payeur',
        'transaction_id', 'url_paiement', 'statut_fournisseur', 'montant_recu', 'reponse_fournisseur',
        'paiement_licence_id', 'user_id', 'verifiee_le', 'payee_le',
    ];

    protected $casts = [
        'montant' => 'integer', 'montant_recu' => 'integer', 'mois' => 'integer',
        'reponse_fournisseur' => 'array', 'verifiee_le' => 'datetime', 'payee_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (CommandeLicence $c) {
            $c->reference ??= 'CMD-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
        });
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function acheteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function paiementLicence(): BelongsTo
    {
        return $this->belongsTo(PaiementLicence::class);
    }

    public function scopeEnAttente(Builder $q): Builder
    {
        return $q->where('statut', 'en_attente');
    }

    /** Moyen utilisé (ou choisi) : « Orange Money », « Kulu »… ; « Au choix » si le client choisit chez Djomy. */
    public function libelleMoyen(): string
    {
        $code = $this->moyen_utilise ?? $this->moyen;

        return $code ? \App\Support\MoyensDjomy::libelle($code) : 'Au choix';
    }

    public function estTest(): bool
    {
        return $this->environnement !== 'production';
    }

    public function estFinale(): bool
    {
        return in_array($this->statut, self::FINAUX, true);
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut][0] ?? $this->statut;
    }

    public function classeStatut(): string
    {
        return self::STATUTS[$this->statut][1] ?? 'etat-neutre';
    }
}
