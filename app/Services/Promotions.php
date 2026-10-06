<?php

namespace App\Services;

use App\Models\Produit;
use App\Models\Promotion;
use Illuminate\Support\Collection;

/**
 * Prix promotionnels.
 * - La promotion la plus avantageuse pour le client s'applique (produit, catégorie ou toute la boutique).
 * - Elle ne se cumule pas avec le prix de gros : le client paie le plus bas des deux.
 * - Jamais sous le prix d'achat, sauf si la boutique autorise la vente à perte.
 * - Un prix saisi à la main (droit de remise) n'est pas modifié.
 */
class Promotions
{
    private ?Collection $enCours = null;

    public function enCours(): Collection
    {
        // Formule sans promotions : prix normaux (les promotions enregistrées sont conservées)
        return $this->enCours ??= fonction('promotions') ? Promotion::enCours()->get() : collect();
    }

    /** Prix après promotion d'une unité vendue (unité ou conditionnement complet), ou null sans promotion. */
    public function prix(Produit $p, bool $conditionnement = false): ?int
    {
        $facteur = $conditionnement && $p->aConditionnement() ? (float) $p->qte_conditionnement : 1.0;
        $base = $conditionnement && $p->aConditionnement() ? $p->prixConditionnement() : (int) $p->prix_vente;

        $meilleur = null;
        foreach ($this->enCours()->filter->concerne($p) as $promo) {
            $prix = $promo->type === 'pourcentage'
                ? (int) round($base * (1 - min(100, $promo->valeur) / 100))
                : (int) round(min($base, $promo->valeur * $facteur));
            $meilleur = $meilleur === null ? $prix : min($meilleur, $prix);
        }
        if ($meilleur === null || $meilleur >= $base) {
            return null;
        }
        if (! boutique()?->vente_a_perte && $p->prix_achat > 0) {
            $meilleur = max($meilleur, (int) round($p->prix_achat * $facteur));
        }

        return $meilleur < $base ? $meilleur : null;
    }

    public function oublier(): void
    {
        $this->enCours = null;
    }
}
