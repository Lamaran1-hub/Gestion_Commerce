<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Boutique extends Model
{
    protected $fillable = [
        'entreprise_id', 'derogations', 'nom', 'responsable_nom', 'responsable_telephone', 'secteur', 'notes_internes', 'slug', 'logo', 'telephone', 'email', 'adresse', 'ville', 'rccm', 'nif',
        'tva_active', 'tva_taux', 'prix_ttc', 'remise_max_pct', 'vente_a_perte', 'plafond_credit_defaut', 'delai_credit_jours', 'delai_annulation_heures', 'delai_retour_jours', 'validite_devis_jours', 'couverture_stock_jours', 'alerte_peremption_jours', 'fidelite_taux', 'fidelite_minimum', 'vitrine_active', 'vitrine_stock_visible', 'vitrine_message', 'objectif_mensuel', 'cadeau_anniversaire', 'periode_verrouillee_jusquau', 'methode_cout', 'resume_quotidien', 'pied_facture', 'couleur', 'couleur_2', 'couleur_3', 'plan_id', 'abonnement_expire_le', 'statut',
    ];

    protected $casts = [
        'tva_active' => 'boolean',
        'vente_a_perte' => 'boolean',
        'resume_quotidien' => 'boolean',
        'remise_max_pct' => 'float',
        'tva_taux' => 'decimal:2',
        'abonnement_expire_le' => 'date',
        'periode_verrouillee_jusquau' => 'date',
        'derogations' => 'array',
        'vitrine_active' => 'boolean',
        'vitrine_stock_visible' => 'boolean',
        'fidelite_taux' => 'float',
        'fidelite_minimum' => 'integer',
    ];

    protected static function booted(): void
    {
        static::creating(function (Boutique $b) {
            if (empty($b->slug)) {
                $base = Str::slug($b->nom) ?: 'boutique';
                $slug = $base;
                $i = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$i++;
                }
                $b->slug = $slug;
            }
        });
    }

    public function entreprise(): BelongsTo
    {
        return $this->belongsTo(Entreprise::class);
    }

    /** Boutique principale du réseau : la première, qui a créé les autres (sa formule fixe le nombre de boutiques). */
    public function principale(): Boutique
    {
        return $this->entreprise_id ? $this->reseau()->first() : $this;
    }

    /** Nombre maximum de boutiques du réseau (null = illimité). */
    public function maxBoutiques(): ?int
    {
        return $this->limite('boutiques');
    }

    /**
     * Limite effective (utilisateurs, produits, boutiques) : dérogation accordée par le propriétaire,
     * sinon celle de la formule (ou de la formule étudiée). null = illimité.
     * Le nombre de boutiques se lit sur la boutique principale du réseau.
     */
    public function limite(string $cle, ?Plan $plan = null): ?int
    {
        if ($cle === 'boutiques' && $this->entreprise_id && ($principale = $this->principale())->id !== $this->id) {
            return $principale->limite($cle, $plan);
        }
        $colonne = config("gestion.limites.{$cle}.0");
        $derogation = $this->derogations[$colonne] ?? null;
        if ($derogation !== null && $derogation !== '') {
            return (int) $derogation === 0 ? null : (int) $derogation; // 0 = illimité pour ce client
        }
        $valeur = ($plan ?? $this->plan)?->{$colonne};

        return $valeur ? (int) $valeur : null;
    }

    /** Utilisation actuelle d'une limite. */
    public function usage(string $cle): int
    {
        return match ($cle) {
            'utilisateurs' => User::where('boutique_id', $this->id)->where('actif', true)->count(),
            'produits' => Produit::withoutGlobalScope('boutique')->where('boutique_id', $this->id)->count(),
            'boutiques' => $this->reseau()->count(),
        };
    }

    /** Refuse l'ajout s'il dépasse la limite effective (message identique partout). */
    public function verifierLimite(string $cle, int $ajout = 1): void
    {
        $max = $this->limite($cle);
        if ($max !== null && $this->usage($cle) + $ajout > $max) {
            $libelle = mb_strtolower(config("gestion.limites.{$cle}.1"));
            throw new \App\Exceptions\OperationRefusee("Votre formule « {$this->principale()->plan?->nom} » est limitée à {$max} {$libelle} "
                ."(vous en avez {$this->usage($cle)}). Passez à une formule supérieure depuis le menu Abonnement, ou contactez l'éditeur.");
        }
    }

    /** Fonction disponible : incluse dans la formule, ou accordée à ce client par le propriétaire. */
    public function aFonction(string $fonction): bool
    {
        return in_array($fonction, $this->derogations['fonctions'] ?? [], true) || ($this->plan?->inclut($fonction) ?? true);
    }

    /** Boutiques du même réseau (elle-même comprise), ou elle seule. */
    public function reseau(): \Illuminate\Support\Collection
    {
        return $this->entreprise_id ? static::where('entreprise_id', $this->entreprise_id)->orderBy('id')->get() : collect([$this]);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function paiementsLicence(): HasMany
    {
        return $this->hasMany(PaiementLicence::class)->latest('paye_le')->latest('id');
    }

    public function demandes(): HasMany
    {
        return $this->hasMany(Demande::class);
    }

    /**
     * Enregistre le paiement d'une licence et prolonge l'accès. La nouvelle période commence
     * au lendemain de l'échéance actuelle si elle n'est pas dépassée : le client ne perd aucun jour.
     *
     * @param  array{montant:int, mois?:int|null, plan_id?:int|null, mode:string, reference?:string|null, paye_le?:string|null, note?:string|null}  $d
     */
    public function enregistrerPaiementLicence(array $d, ?User $auteur = null): PaiementLicence
    {
        return DB::transaction(function () use ($d, $auteur) {
            $illimitee = empty($d['mois']);
            $depart = $this->abonnement_expire_le && $this->abonnement_expire_le->isFuture()
                ? $this->abonnement_expire_le->copy()->addDay() : now()->startOfDay();
            $fin = $illimitee ? null : $depart->copy()->addMonthsNoOverflow((int) $d['mois'])->subDay();

            $paiement = PaiementLicence::create([
                'boutique_id' => $this->id,
                'plan_id' => $d['plan_id'] ?? $this->plan_id,
                'montant' => (int) $d['montant'],
                'mois' => $illimitee ? null : (int) $d['mois'],
                'periode_du' => $depart->toDateString(),
                'periode_au' => $fin?->toDateString(),
                'mode' => $d['mode'],
                'reference' => $d['reference'] ?? null,
                'paye_le' => $d['paye_le'] ?? now()->toDateString(),
                'note' => $d['note'] ?? null,
                'user_id' => $auteur?->id,
            ]);
            $this->update([
                'statut' => 'actif',
                'plan_id' => $d['plan_id'] ?? $this->plan_id,
                'abonnement_expire_le' => $fin?->toDateString(),
            ]);

            return $paiement;
        });
    }

    /**
     * Ce que l'usage réel de la boutique dépasse dans une formule (utilisateurs actifs, produits, boutiques du réseau).
     * Vide = la formule convient.
     *
     * @return string[]
     */
    public function depassementsFormule(Plan $plan): array
    {
        $depassements = [];
        foreach (config('gestion.limites') as $cle => [, $libelle]) {
            // Le nombre de points de vente se juge sur la boutique principale du réseau
            if ($cle === 'boutiques' && $this->entreprise_id && $this->principale()->id !== $this->id) {
                continue;
            }
            $max = $this->limite($cle, $plan);
            $usage = $this->usage($cle);
            if ($max !== null && $usage > $max) {
                $depassements[] = $cle === 'boutiques' ? "{$usage} boutiques dans le réseau (maximum {$max})"
                    : "{$usage} ".mb_strtolower($libelle)." (maximum {$max})";
            }
        }

        return $depassements;
    }

    /**
     * Période clôturée : les comptes arrêtés (mois déclaré, inventaire fait) ne bougent plus.
     * Toute opération qui modifierait une date antérieure ou égale au verrou est refusée.
     */
    public function verifierPeriodeOuverte(\DateTimeInterface|string|null $date, string $operation): void
    {
        $verrou = $this->periode_verrouillee_jusquau;
        if (! $verrou || ! $date) {
            return;
        }
        $jour = \Carbon\Carbon::parse($date)->startOfDay();
        if ($jour->lte($verrou)) {
            throw new \App\Exceptions\OperationRefusee("La période jusqu'au ".$verrou->format('d/m/Y')." est clôturée : on ne peut plus {$operation}. "
                ."Enregistrez l'opération de correction à la date d'aujourd'hui, ou demandez à l'administrateur de rouvrir la période.");
        }
    }

    /** La boutique peut-elle travailler aujourd'hui ? */
    public function estActive(): bool
    {
        if ($this->statut === 'suspendu') {
            return false;
        }

        return $this->abonnement_expire_le === null
            || $this->abonnement_expire_le->copy()->addDays(self::joursGrace())->endOfDay()->isFuture();
    }

    /**
     * Délai de grâce (réglé par le propriétaire) : après l'échéance, la boutique continue
     * de travailler quelques jours avec un avertissement, le temps de payer.
     */
    public static function joursGrace(): int
    {
        return (int) \App\Support\Plateforme::get('jours_grace', '0');
    }

    /** Échéance dépassée mais encore dans le délai de grâce. */
    public function enPeriodeDeGrace(): bool
    {
        return $this->statut !== 'suspendu' && $this->abonnement_expire_le !== null
            && $this->abonnement_expire_le->endOfDay()->isPast() && $this->estActive();
    }

    /** Dernier jour de travail possible (échéance + délai de grâce). */
    public function dateCoupure(): ?\Carbon\Carbon
    {
        return $this->abonnement_expire_le?->copy()->addDays(self::joursGrace());
    }

    public function joursRestants(): ?int
    {
        if (! $this->abonnement_expire_le) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->abonnement_expire_le->startOfDay(), false);
    }

    /** Licence payée : abonnement activé par l'éditeur (hors essai) et non expiré. */
    public function licencePayee(): bool
    {
        return $this->statut === 'actif' && $this->estActive();
    }

    /**
     * Logo affiché (menu, onglet, connexion, documents) : celui de la boutique si la licence
     * est payée, sinon aucun (le logo du logiciel prend le relais).
     * L'adresse suit l'hôte réellement utilisé (localhost, 127.0.0.1, domaine…), pas APP_URL.
     */
    public function logoUrl(): ?string
    {
        return $this->licencePayee() ? $this->logoTeleverseUrl() : null;
    }

    /** Logo téléversé, qu'il soit affiché ou non (aperçu dans les paramètres). */
    public function logoTeleverseUrl(): ?string
    {
        return $this->logo && Storage::disk('public')->exists($this->logo) ? asset('storage/'.$this->logo) : null;
    }

    /** Chemin absolu du logo, utilisé par dompdf et l'export Excel. */
    public function logoChemin(): ?string
    {
        return $this->licencePayee() && $this->logo && Storage::disk('public')->exists($this->logo)
            ? Storage::disk('public')->path($this->logo) : null;
    }

    /** Couleurs de l'entreprise (1 à 3), la principale en premier. */
    public function couleurs(): array
    {
        return array_values(array_filter([$this->couleur ?: '#1F6F54', $this->couleur_2, $this->couleur_3]));
    }

    /** Couleur secondaire (fonds sombres : menu, totaux) ; à défaut, la principale. */
    public function couleurSecondaire(): string
    {
        return $this->couleur_2 ?: ($this->couleur ?: '#1F6F54');
    }

    /** Couleur d'accent (pastilles, filets des documents) ; à défaut, la secondaire. */
    public function couleurAccent(): string
    {
        return $this->couleur_3 ?: $this->couleurSecondaire();
    }

    /** Assombrit une couleur jusqu'à ce qu'un texte blanc y reste lisible (contraste ≥ 10:1). */
    public static function versionSombre(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        while (self::luminance($r, $g, $b) > 0.045) {
            [$r, $g, $b] = [(int) ($r * .88), (int) ($g * .88), (int) ($b * .88)];
        }

        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }

    /** Texte noir ou blanc selon la couleur de fond. */
    public static function texteSur(string $hex): string
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return self::luminance($r, $g, $b) > 0.4 ? '#1C2622' : '#FFFFFF';
    }

    private static function luminance(int $r, int $g, int $b): float
    {
        $c = fn (int $v) => ($v /= 255) <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $c($r) + 0.7152 * $c($g) + 0.0722 * $c($b);
    }

    /** Variables CSS de la charte (menu, boutons, accents). */
    public function variablesCss(): string
    {
        $fonce = self::versionSombre($this->couleurSecondaire());

        return "--marque: {$this->couleur}; --foret: {$fonce}; --accent: {$this->couleurAccent()}; --sur-accent: ".self::texteSur($this->couleurAccent()).';';
    }

    /** Coordonnées sur une ligne, pour l'en-tête des documents. */
    public function coordonnees(): array
    {
        return array_values(array_filter([
            collect([$this->adresse, $this->ville])->filter()->implode(', '),
            $this->telephone ? 'Tél : '.$this->telephone : null,
            $this->email,
            collect([$this->rccm ? 'RCCM : '.$this->rccm : null, $this->nif ? 'NIF : '.$this->nif : null])->filter()->implode(' · '),
        ]));
    }

    public function initiales(): string
    {
        return Str::upper(collect(explode(' ', $this->nom))->filter()->take(2)->map(fn ($m) => Str::substr($m, 0, 1))->implode(''));
    }

    public function libelleStatut(): string
    {
        if ($this->statut === 'suspendu') {
            return 'Suspendue';
        }
        if (! $this->estActive()) {
            return 'Expirée';
        }

        return $this->statut === 'essai' ? "Période d'essai" : 'Active';
    }
}
