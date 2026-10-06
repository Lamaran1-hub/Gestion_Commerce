<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace d'un e-mail envoyé par le logiciel (journal consultable par le propriétaire). */
class EmailEnvoye extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'emails_envoyes';

    protected $fillable = ['boutique_id', 'user_id', 'destinataire', 'sujet', 'type', 'statut', 'erreur'];

    public const TYPES = [
        'bienvenue' => 'Bienvenue', 'licence' => 'Licence et paiements', 'rappel' => 'Rappels d\'échéance', 'compte' => 'Compte (suspension, réactivation)',
        'nouveaute' => 'Nouveautés', 'resume' => 'Résumé quotidien', 'test' => 'Test', 'proprietaire' => 'Alertes du propriétaire',
    ];

    public const STATUTS = ['envoye' => 'Envoyé', 'simule' => 'Simulé (envoi réel non configuré)', 'echec' => 'Échec'];

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }
}
