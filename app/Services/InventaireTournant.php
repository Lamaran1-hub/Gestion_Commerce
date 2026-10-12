<?php

namespace App\Services;

use App\Models\Produit;
use Illuminate\Support\Collection;

/**
 * Inventaire tournant : au lieu de fermer la boutique pour tout compter, on compte chaque jour
 * quelques produits. Priorité aux produits jamais comptés, puis aux plus anciens comptages ;
 * à égalité, ceux qui immobilisent le plus d'argent. Tout le stock est ainsi vérifié en un cycle.
 */
class InventaireTournant
{
    /** Un produit compté depuis moins de JOURS_CYCLE jours est considéré comme vérifié. */
    public const JOURS_CYCLE = 30;

    public function parJour(): int
    {
        return max(1, (int) config('gestion.comptage_par_jour', 10));
    }

    /** Produits à compter aujourd'hui (la liste raccourcit à mesure qu'ils sont comptés). */
    public function duJour(): Collection
    {
        $dejaComptes = Produit::stockables()->where('actif', true)->where('compte_le', '>=', today())->count();
        $reste = $this->parJour() - $dejaComptes;
        if ($reste <= 0) {
            return collect();
        }

        return Produit::stockables()->where('actif', true)
            ->where(fn ($q) => $q->whereNull('compte_le')->orWhere('compte_le', '<', now()->subDays(self::JOURS_CYCLE)))
            ->orderByRaw('compte_le IS NOT NULL')->orderBy('compte_le')
            ->orderByRaw('(CASE WHEN stock > 0 THEN stock ELSE 0 END) * prix_achat DESC')->orderBy('id')
            ->limit($reste)->get();
    }

    /** Comptés aujourd'hui, total à compter, part du stock vérifiée sur le cycle. */
    public function couverture(): array
    {
        $base = Produit::stockables()->where('actif', true);
        $total = (clone $base)->count();
        $verifies = (clone $base)->where('compte_le', '>=', now()->subDays(self::JOURS_CYCLE))->count();

        return [
            'total' => $total,
            'verifies' => $verifies,
            'pct' => $total ? (int) floor($verifies * 100 / $total) : 100,
            'aujourdhui' => (clone $base)->where('compte_le', '>=', today())->count(),
        ];
    }

    /** Note la date de comptage, même quand le stock était juste (le produit est vérifié). */
    public function marquer(Collection $ids): void
    {
        if ($ids->isNotEmpty()) {
            Produit::whereIn('id', $ids)->update(['compte_le' => now()]);
        }
    }
}
