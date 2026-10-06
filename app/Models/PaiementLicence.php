<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Paiement d'une licence par une boutique cliente (côté propriétaire du logiciel). */
class PaiementLicence extends Model
{
    protected $table = 'paiements_licence';

    protected $fillable = ['numero', 'boutique_id', 'plan_id', 'montant', 'mois', 'periode_du', 'periode_au', 'mode', 'reference', 'paye_le', 'note', 'user_id'];

    protected $casts = ['montant' => 'integer', 'mois' => 'integer', 'periode_du' => 'date', 'periode_au' => 'date', 'paye_le' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (PaiementLicence $p) {
            if (! $p->numero) {
                $annee = now()->year;
                $n = static::where('numero', 'like', "LIC-{$annee}-%")->count() + 1;
                do {
                    $p->numero = sprintf('LIC-%d-%05d', $annee, $n++);
                } while (static::where('numero', $p->numero)->exists());
            }
        });
    }

    /** Paiements réels : hors paiements de test du mode sandbox. */
    public function scopeReels(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('mode', '!=', 'djomy_test');
    }

    public function estTest(): bool
    {
        return $this->mode === 'djomy_test';
    }

    public function boutique(): BelongsTo
    {
        return $this->belongsTo(Boutique::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleMode(): string
    {
        return match ($this->mode) {
            'djomy' => 'Paiement en ligne (Djomy)',
            'djomy_test' => 'Paiement en ligne — TEST',
            default => config('gestion.modes_paiement')[$this->mode] ?? $this->mode,
        };
    }

    public function libellePeriode(): string
    {
        return 'du '.$this->periode_du->format('d/m/Y').($this->periode_au ? ' au '.$this->periode_au->format('d/m/Y') : ', sans échéance');
    }
}
