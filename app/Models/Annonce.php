<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Message du propriétaire aux utilisateurs : nouveauté, information, maintenance. */
class Annonce extends Model
{
    public const TYPES = [
        'nouveaute' => ['Nouveauté', 'stars', 'etat-ok'],
        'info' => ['Information', 'info-circle', 'etat-neutre'],
        'maintenance' => ['Maintenance', 'tools', 'etat-alerte'],
        'important' => ['Important', 'exclamation-triangle', 'etat-rupture'],
    ];

    protected $fillable = ['titre', 'contenu', 'type', 'boutique_id', 'publiee_le', 'expire_le', 'user_id'];

    protected $casts = ['publiee_le' => 'datetime', 'expire_le' => 'date', 'email_envoye_le' => 'datetime'];

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function lecteurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'annonce_lectures')->withPivot('lue_le');
    }

    /** Annonces visibles par un utilisateur de boutique : publiées, non expirées, pour tous ou pour sa boutique. */
    public function scopePourUtilisateur(Builder $q, User $user): Builder
    {
        return $q->whereNotNull('publiee_le')->where('publiee_le', '<=', now())
            ->where(fn ($s) => $s->whereNull('expire_le')->orWhere('expire_le', '>=', now()->toDateString()))
            ->where(fn ($s) => $s->whereNull('boutique_id')->orWhere('boutique_id', $user->boutique_id));
    }

    public function scopeNonLuesPar(Builder $q, User $user): Builder
    {
        return $q->whereDoesntHave('lecteurs', fn ($s) => $s->where('users.id', $user->id));
    }

    public function libelleType(): string
    {
        return self::TYPES[$this->type][0] ?? $this->type;
    }

    public function icone(): string
    {
        return self::TYPES[$this->type][1] ?? 'megaphone';
    }

    public function classeEtat(): string
    {
        return self::TYPES[$this->type][2] ?? 'etat-neutre';
    }
}
