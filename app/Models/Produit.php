<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use App\Models\Concerns\Horodatage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Produit extends Model
{
    use AppartientABoutique, Horodatage, SoftDeletes;

    /** Chaque changement de prix est noté dans l'historique (et, pour le prix de vente, dans le journal d'activité). */
    protected static function booted(): void
    {
        static::updated(function (Produit $p) {
            foreach (array_keys(HistoriquePrix::CHAMPS) as $champ) {
                if (! $p->wasChanged($champ)) {
                    continue;
                }
                $ancien = $p->getOriginal($champ);
                $nouveau = $p->getAttribute($champ);
                if ((int) $ancien === (int) $nouveau && ($ancien === null) === ($nouveau === null)) {
                    continue;   // « 90000.00 » → « 90000 » : pas un vrai changement
                }
                HistoriquePrix::create(['boutique_id' => $p->boutique_id, 'produit_id' => $p->id, 'champ' => $champ,
                    'ancien' => $ancien, 'nouveau' => $nouveau, 'origine' => mb_substr(HistoriquePrix::origineCourante(), 0, 60), 'user_id' => auth()->id()]);
                // Prix de vente au journal (sauf conversion en masse au passage TTC/HT, résumée par un seul message)
                if ($champ === 'prix_vente' && ! str_starts_with(HistoriquePrix::origineCourante(), 'Passage aux prix')) {
                    JournalActivite::noterPour($p->boutique_id, 'prix', "Prix de vente de {$p->designation} : ".gnf((int) $ancien).' → '.gnf((int) $nouveau)
                        .' ('.HistoriquePrix::origineCourante().')');
                }
            }
        });
    }

    public function historiquePrix(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(HistoriquePrix::class)->latest('created_at')->latest('id');
    }

    // « stock » n'est volontairement pas modifiable en masse : il passe par StockService
    protected $fillable = [
        'categorie_id', 'fournisseur_id', 'designation', 'code_barre', 'unite', 'conditionnement', 'qte_conditionnement', 'prix_conditionnement',
        'prix_achat', 'prix_vente', 'taux_tva', 'prix_gros', 'quantite_gros', 'seuil_alerte', 'image', 'actif', 'en_vitrine', 'garantie_mois', 'suivi_serie',
    ];

    protected $casts = [
        'garantie_mois' => 'integer', 'suivi_serie' => 'boolean',
        'prix_achat' => 'integer',
        'prix_vente' => 'integer',
        'taux_tva' => 'float',
        'en_vitrine' => 'boolean',
        'prix_gros' => 'integer',
        'quantite_gros' => 'float',
        'qte_conditionnement' => 'float',
        'prix_conditionnement' => 'integer',
        'stock' => 'float',
        'seuil_alerte' => 'float',
        'actif' => 'boolean',
    ];

    /**
     * Prix unitaire applicable : prix de gros pour un client grossiste, ou à partir de la
     * quantité de gros ; sinon prix de détail.
     */
    public function prixPour(float $quantite, ?Client $client = null): int
    {
        if ($this->prix_gros && ($client?->grossiste || ($this->quantite_gros && $quantite >= $this->quantite_gros))) {
            return $this->prix_gros;
        }

        return $this->prix_vente;
    }

    /** Le produit se vend-il aussi par conditionnement (carton, casier…) ? */
    public function aConditionnement(): bool
    {
        return $this->conditionnement && $this->qte_conditionnement > 1;
    }

    /** Prix d'un conditionnement complet (prix dédié, sinon unités × prix de détail). */
    public function prixConditionnement(): int
    {
        return $this->prix_conditionnement ?: (int) round($this->prix_vente * $this->qte_conditionnement);
    }

    /** Libellé du conditionnement : « carton de 12 bouteilles ». */
    public function libelleConditionnement(): string
    {
        return $this->conditionnement.' de '.qte($this->qte_conditionnement).' '.$this->unite;
    }

    public function categorie(): BelongsTo
    {
        return $this->belongsTo(Categorie::class);
    }

    public function fournisseur(): BelongsTo
    {
        return $this->belongsTo(Fournisseur::class)->withTrashed();
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementStock::class);
    }

    public function scopeEnAlerte(Builder $q): Builder
    {
        return $q->whereColumn('stock', '<=', 'seuil_alerte');
    }

    public function scopeRecherche(Builder $q, ?string $terme): Builder
    {
        if (! $terme) {
            return $q;
        }

        return $q->where(fn ($s) => $s->where('designation', 'like', "%{$terme}%")->orWhere('code_barre', $terme));
    }

    public function enAlerte(): bool
    {
        return $this->stock <= $this->seuil_alerte;
    }

    public function etatStock(): string
    {
        return $this->stock <= 0 ? 'rupture' : ($this->enAlerte() ? 'alerte' : 'ok');
    }

    public function imageUrl(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    public function valeurStock(): int
    {
        return (int) round(max($this->stock, 0) * $this->prix_achat);
    }
}
