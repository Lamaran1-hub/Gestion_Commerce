<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un changement de prix d'un produit. Enregistré automatiquement par le modèle Produit,
 * quelle que soit l'origine du changement (fiche, réception, import, transfert, passage TVA…).
 */
class HistoriquePrix extends Model
{
    use AppartientABoutique;

    public const UPDATED_AT = null;

    protected $table = 'historique_prix';

    protected $fillable = ['boutique_id', 'produit_id', 'champ', 'ancien', 'nouveau', 'origine', 'user_id'];

    protected $casts = ['ancien' => 'integer', 'nouveau' => 'integer'];

    /** Prix suivis, avec leur libellé. */
    public const CHAMPS = [
        'prix_vente' => 'Prix de vente',
        'prix_achat' => "Prix d'achat",
        'prix_gros' => 'Prix de gros',
        'prix_conditionnement' => 'Prix par conditionnement',
    ];

    /** Origine du changement en cours (fixée par le service qui modifie les prix ; « Fiche produit » sinon). */
    private static ?string $origine = null;

    /** Exécute $fn en attribuant les changements de prix à $origine. */
    public static function depuis(string $origine, callable $fn): mixed
    {
        $precedente = self::$origine;
        self::$origine = $origine;
        try {
            return $fn();
        } finally {
            self::$origine = $precedente;
        }
    }

    public static function origineCourante(): string
    {
        return self::$origine ?? 'Fiche produit';
    }

    public function produit(): BelongsTo
    {
        return $this->belongsTo(Produit::class);
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function libelleChamp(): string
    {
        return self::CHAMPS[$this->champ] ?? $this->champ;
    }

    /** Variation en % (null si pas d'ancien prix). */
    public function variation(): ?float
    {
        return $this->ancien ? round(($this->nouveau - $this->ancien) * 100 / $this->ancien, 1) : null;
    }
}
