<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use App\Models\Concerns\Horodatage;
use App\Services\NumeroService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use AppartientABoutique, Horodatage, SoftDeletes;

    protected $fillable = ['code', 'nom', 'prenom', 'telephone', 'email', 'adresse', 'quartier', 'commune', 'ville', 'plafond_credit', 'grossiste', 'notes', 'date_naissance'];

    protected $casts = ['grossiste' => 'boolean', 'plafond_credit' => 'integer', 'derniere_relance_le' => 'datetime', 'derniere_invitation_le' => 'datetime', 'points' => 'integer', 'date_naissance' => 'date', 'dernier_voeu_le' => 'date'];

    protected static function booted(): void
    {
        static::creating(function (Client $c) {
            $c->code ??= app(NumeroService::class)->suivant('client', 'CLI', annuel: false);
        });
    }

    public function ventes(): HasMany
    {
        return $this->hasMany(Vente::class);
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /** Prochain anniversaire (aujourd'hui compris) ; un 29 février est fêté le 28 les années non bissextiles. */
    public function prochainAnniversaire(): ?\Carbon\Carbon
    {
        if (! $this->date_naissance) {
            return null;
        }
        $aujourdhui = now()->startOfDay();
        foreach ([$aujourdhui->year, $aujourdhui->year + 1] as $annee) {
            $jour = min($this->date_naissance->day, \Carbon\Carbon::create($annee, $this->date_naissance->month, 1)->daysInMonth);
            $date = \Carbon\Carbon::create($annee, $this->date_naissance->month, $jour)->startOfDay();
            if ($date->gte($aujourdhui)) {
                return $date;
            }
        }

        return null;
    }

    /** Nombre de jours avant l'anniversaire (0 = aujourd'hui), null si date inconnue. */
    public function joursAvantAnniversaire(): ?int
    {
        $d = $this->prochainAnniversaire();

        return $d ? (int) now()->startOfDay()->diffInDays($d) : null;
    }

    public function voeuEnvoyeCetteAnnee(): bool
    {
        return (bool) $this->dernier_voeu_le?->isSameYear(now());
    }

    public function nomComplet(): string
    {
        return trim($this->prenom.' '.$this->nom);
    }

    /** Adresse de résidence sur une ligne : précision, quartier, commune, ville. */
    public function residence(): string
    {
        return collect([$this->adresse, $this->quartier, $this->commune, $this->ville])->filter()->implode(', ');
    }

    /** Montant encore dû par le client sur ses ventes validées (crédits). */
    public function soldeDu(): int
    {
        return (int) $this->ventes()->where('statut', 'validee')
            ->selectRaw('COALESCE(SUM(total_ttc - montant_paye), 0) as du')->value('du');
    }

    public function scopeRecherche(Builder $q, ?string $terme): Builder
    {
        if (! $terme) {
            return $q;
        }

        return $q->where(fn ($s) => $s->where('nom', 'like', "%{$terme}%")
            ->orWhere('prenom', 'like', "%{$terme}%")
            ->orWhere('telephone', 'like', "%{$terme}%")
            ->orWhere('code', $terme));
    }
}
