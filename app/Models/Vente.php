<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Vente extends Model
{
    use AppartientABoutique;

    protected $fillable = [
        'numero', 'client_id', 'date_vente', 'total_ht', 'remise', 'tva_taux', 'total_tva',
        'total_ttc', 'montant_paye', 'montant_retourne', 'statut', 'note', 'user_id', 'annulee_le', 'annulee_par', 'motif_annulation',
        'uuid_hors_ligne', 'synchronisee_le', 'echeance', 'prix_ttc',
        'livraison', 'livraison_adresse', 'livraison_contact', 'livraison_prevue_le', 'livreur', 'livree_le', 'livree_a',
    ];

    protected $casts = [
        'date_vente' => 'datetime',
        'annulee_le' => 'datetime',
        'livraison_prevue_le' => 'date', 'livree_le' => 'datetime', 'echeance' => 'date', 'prix_ttc' => 'boolean',
        'total_ht' => 'integer', 'remise' => 'integer', 'total_tva' => 'integer',
        'total_ttc' => 'integer', 'montant_paye' => 'integer', 'montant_retourne' => 'integer',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function vendeur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneVente::class);
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

    /** Ventilation de la TVA par taux (base après remise, montant), pour la facture et le ticket. */
    public function ventilationTva(): array
    {
        return \App\Support\Tva::ventiler($this->lignes->map(fn ($l) => ['total' => $l->total, 'taux' => $l->taux_tva ?? $this->tva_taux]),
            (int) $this->lignes->sum('total'), (int) $this->remise, (bool) $this->prix_ttc)['ventilation'];
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function retours(): HasMany
    {
        return $this->hasMany(Retour::class)->latest('id');
    }

    /** Montant initial de la vente, avant retours de marchandise. */
    public function totalInitial(): int
    {
        return $this->total_ttc + (int) $this->montant_retourne;
    }

    public function resteAPayer(): int
    {
        return $this->statut === 'annulee' ? 0 : max(0, $this->total_ttc - $this->montant_paye);
    }

    public function etatPaiement(): string
    {
        if ($this->statut === 'annulee') {
            return 'annulee';
        }
        if ($this->montant_paye >= $this->total_ttc) {
            return 'payee';
        }

        return $this->montant_paye > 0 ? 'partielle' : 'credit';
    }

    public function marge(): int
    {
        // Marge hors taxe : en prix TTC, la TVA comprise dans les prix (et dans la remise) est retirée
        $ventes = $this->lignes->sum(fn ($l) => \App\Support\Tva::horsTaxe($l->prix_unitaire * $l->quantite, $l->taux_tva ?? $this->tva_taux, (bool) $this->prix_ttc));
        $cout = $this->lignes->sum(fn ($l) => $l->prix_achat * $l->quantite);
        $remiseHt = $this->prix_ttc && $this->lignes->sum('total') > 0 ? $this->remise * $ventes / $this->lignes->sum('total') : $this->remise;

        return (int) round($ventes - $cout - $remiseHt);
    }

    public function scopeValidees(Builder $q): Builder
    {
        return $q->where('statut', 'validee');
    }

    public const LIVRAISONS = ['a_livrer' => 'À livrer', 'en_route' => 'En route', 'livree' => 'Livrée'];

    /** Livraisons pas encore faites (ventes validées à livrer ou en route). */
    public function scopeALivrer(Builder $q): Builder
    {
        return $q->validees()->whereIn('livraison', ['a_livrer', 'en_route']);
    }

    public function livraisonEnRetard(): bool
    {
        return in_array($this->livraison, ['a_livrer', 'en_route'], true) && $this->livraison_prevue_le && $this->livraison_prevue_le->endOfDay()->isPast();
    }

    public function libelleLivraison(): ?string
    {
        return self::LIVRAISONS[$this->livraison] ?? null;
    }

    public function scopeAvecReste(Builder $q): Builder
    {
        return $q->validees()->whereColumn('montant_paye', '<', 'total_ttc');
    }

    /** Crédits dont la date de paiement promise est dépassée. */
    public function scopeEnRetard(Builder $q): Builder
    {
        return $q->avecReste()->whereNotNull('echeance')->whereDate('echeance', '<', now()->toDateString());
    }

    public function creditEnRetard(): bool
    {
        return $this->resteAPayer() > 0 && $this->echeance && $this->echeance->lt(now()->startOfDay());
    }

    /** Échéance proposée pour un crédit : délai de crédit de la boutique, 30 jours à défaut. */
    public static function echeanceParDefaut(\DateTimeInterface $depuis, ?Boutique $boutique = null): \Illuminate\Support\Carbon
    {
        return \Illuminate\Support\Carbon::instance($depuis)->addDays((int) (($boutique ?? boutique())?->delai_credit_jours ?: 30))->startOfDay();
    }
}
