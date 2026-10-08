<?php

namespace App\Models;

use App\Models\Concerns\AppartientABoutique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Rapport Z : bilan de caisse d'un caissier pour une journée. */
class ClotureCaisse extends Model
{
    use AppartientABoutique;

    protected $table = 'clotures_caisse';

    protected $fillable = [
        'boutique_id', 'user_id', 'jour', 'nb_ventes', 'total_ventes', 'encaissements', 'sorties_especes', 'especes_theoriques',
        'especes_comptees', 'billetage', 'ecart', 'motif_ecart', 'note', 'cloturee_par',
    ];

    protected $casts = [
        'jour' => 'date', 'encaissements' => 'array', 'nb_ventes' => 'integer', 'total_ventes' => 'integer',
        'especes_theoriques' => 'integer', 'especes_comptees' => 'integer', 'ecart' => 'integer', 'sorties_especes' => 'integer',
        'billetage' => 'array',
    ];

    /**
     * Total d'un comptage billet par billet (coupure => nombre), en ne gardant que les coupures connues.
     *
     * @param  array<int|string,int|string|null>  $billetage
     * @return array{0: array<int,int>, 1: int} [détail filtré, total]
     */
    public static function compterBillets(array $billetage): array
    {
        $detail = [];
        foreach (config('gestion.coupures') as $coupure) {
            $nombre = (int) ($billetage[$coupure] ?? 0);
            if ($nombre > 0) {
                $detail[$coupure] = $nombre;
            }
        }

        return [$detail, (int) array_sum(array_map(fn ($c, $n) => $c * $n, array_keys($detail), $detail))];
    }

    public function caissier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function auteur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cloturee_par');
    }

    /**
     * Modes qui ne sont pas de l'argent encaissé ce jour-là : affichés à part, hors total.
     * Carte cadeau : l'argent est entré le jour où la carte a été vendue, pas quand elle est dépensée.
     */
    public const HORS_ARGENT = ['fidelite', 'avoir', 'carte_cadeau', 'echange'];

    public function totalEncaisse(): int
    {
        return (int) array_sum(array_diff_key($this->encaissements ?? [], array_flip(self::HORS_ARGENT)));
    }

    public function libelleEcart(): string
    {
        return match (true) {
            $this->ecart === 0 => 'Caisse juste',
            $this->ecart > 0 => 'Excédent de '.gnf($this->ecart),
            default => 'Manque de '.gnf(abs($this->ecart)),
        };
    }
}
