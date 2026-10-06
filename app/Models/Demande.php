<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Demande d'assistance d'une boutique au propriétaire du logiciel (échange de messages). */
class Demande extends Model
{
    public const CATEGORIES = [
        'question' => 'Question sur le logiciel',
        'probleme' => 'Problème ou erreur',
        'licence' => 'Licence et paiement',
        'suggestion' => "Suggestion d'amélioration",
        'autre' => 'Autre',
    ];

    public const STATUTS = ['ouverte' => 'En attente de réponse', 'repondue' => 'Répondue', 'fermee' => 'Clôturée'];

    protected $fillable = ['boutique_id', 'user_id', 'sujet', 'categorie', 'statut', 'lue_proprietaire', 'lue_boutique', 'dernier_message_le'];

    protected $casts = ['lue_proprietaire' => 'boolean', 'lue_boutique' => 'boolean', 'dernier_message_le' => 'datetime'];

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DemandeMessage::class)->oldest('id');
    }

    /** Ajoute un message et met à jour l'état « lu » de chaque côté. */
    public function repondre(User $auteur, string $contenu): DemandeMessage
    {
        $duProprietaire = (bool) $auteur->est_super_admin;
        $message = $this->messages()->create(['user_id' => $auteur->id, 'du_proprietaire' => $duProprietaire, 'contenu' => $contenu]);
        $this->update([
            'statut' => $duProprietaire ? 'repondue' : 'ouverte',
            'lue_proprietaire' => $duProprietaire,
            'lue_boutique' => ! $duProprietaire,
            'dernier_message_le' => now(),
        ]);

        return $message;
    }

    public function libelleCategorie(): string
    {
        return self::CATEGORIES[$this->categorie] ?? $this->categorie;
    }

    public function libelleStatut(): string
    {
        return self::STATUTS[$this->statut] ?? $this->statut;
    }

    public function classeStatut(): string
    {
        return ['ouverte' => 'etat-alerte', 'repondue' => 'etat-ok', 'fermee' => 'etat-neutre'][$this->statut] ?? 'etat-neutre';
    }
}
