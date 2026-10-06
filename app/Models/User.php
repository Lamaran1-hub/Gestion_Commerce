<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $fillable = [
        'boutique_id', 'role_id', 'prenom', 'nom', 'email', 'telephone', 'password',
        'est_super_admin', 'actif', 'doit_changer_mot_de_passe', 'derniere_connexion', 'recevoir_nouveautes', 'objectif_mensuel', 'commission_pct',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = ['actif' => true, 'est_super_admin' => false, 'doit_changer_mot_de_passe' => false];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'est_super_admin' => 'boolean',
            'actif' => 'boolean',
            'doit_changer_mot_de_passe' => 'boolean',
            'derniere_connexion' => 'datetime',
            'recevoir_nouveautes' => 'boolean',
            'objectif_mensuel' => 'integer',
            'commission_pct' => 'float',
        ];
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function role(): BelongsTo
    {
        // Le rôle est lu sans filtre de boutique : l'utilisateur ne peut avoir qu'un rôle de sa boutique
        return $this->belongsTo(Role::class)->withoutGlobalScope('boutique');
    }

    /** Lien de réinitialisation envoyé en français, avec notre propre gabarit. */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ReinitialisationMotDePasse($token));
    }

    public function annoncesLues(): BelongsToMany
    {
        return $this->belongsToMany(Annonce::class, 'annonce_lectures')->withPivot('lue_le');
    }

    public function nomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    public function initiales(): string
    {
        return mb_strtoupper(mb_substr($this->prenom, 0, 1).mb_substr($this->nom, 0, 1));
    }

    public function estAdministrateurBoutique(): bool
    {
        return (bool) $this->role?->systeme;
    }

    /**
     * Boutiques que l'utilisateur peut ouvrir : l'administrateur voit tout le réseau de sa boutique ;
     * les employés restent dans leur boutique.
     */
    public function boutiquesAccessibles(): \Illuminate\Support\Collection
    {
        if (! $this->boutique) {
            return collect();
        }

        return $this->role?->systeme ? $this->boutique->reseau() : collect([$this->boutique]);
    }

    public function aPermission(string $permission): bool
    {
        if ($this->est_super_admin || ! $this->actif) {
            return false; // le super-admin gère la plateforme, pas le contenu des boutiques
        }

        return (bool) $this->role?->autorise($permission);
    }
}
