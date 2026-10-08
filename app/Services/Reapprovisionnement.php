<?php

namespace App\Services;

use App\Models\Produit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Suggestions de réapprovisionnement à partir des ventes réelles.
 *
 * - Rythme de vente = unités vendues (nettes des retours) sur les 30 derniers jours ÷ 30.
 * - Un produit est à commander s'il est sous son seuil d'alerte, ou s'il ne couvre plus
 *   le nombre de jours voulu (réglage de la boutique, 14 par défaut).
 * - Quantité suggérée = de quoi tenir ce nombre de jours, arrondie au conditionnement complet.
 * - Produit dormant = du stock mais aucune vente depuis 60 jours (argent immobilisé).
 */
class Reapprovisionnement
{
    public const JOURS_ANALYSE = 30;

    public const JOURS_DORMANT = 60;

    /** @return Collection<int, array> */
    public function suggestions(): Collection
    {
        $couverture = boutique()->couverture_stock_jours ?: 14;
        $ventes = $this->unitesVendues(now()->subDays(self::JOURS_ANALYSE));
        $enCommande = app(CommandesFournisseur::class)->enCommande();   // déjà commandé, pas encore arrivé

        return Produit::stockables()->where('actif', true)->with('fournisseur')->get()->map(function (Produit $p) use ($ventes, $couverture, $enCommande) {
            $vendu = (float) ($ventes[$p->id] ?? 0);
            $parJour = $vendu / self::JOURS_ANALYSE;
            $stock = max(0.0, (float) $p->stock) + (float) ($enCommande[$p->id] ?? 0);   // stock « à venir » compris
            $joursRestants = $parJour > 0 ? $stock / $parJour : null;

            $sousSeuil = $p->seuil_alerte > 0 && $stock <= $p->seuil_alerte;
            $bientotEpuise = $joursRestants !== null && $joursRestants < $couverture;
            if (! $sousSeuil && ! $bientotEpuise) {
                return null;
            }

            // De quoi tenir la période voulue ; sans historique, on remonte au double du seuil
            $besoin = $parJour > 0 ? $parJour * $couverture - $stock : $p->seuil_alerte * 2 - $stock;
            $besoin = max($besoin, 1);
            $conditionnements = null;
            if ($p->aConditionnement()) {
                $conditionnements = (int) ceil($besoin / $p->qte_conditionnement);
                $besoin = $conditionnements * $p->qte_conditionnement;
            } else {
                $besoin = ceil($besoin);
            }

            return [
                'produit' => $p,
                'vendu' => $vendu,
                'par_jour' => round($parJour, 2),
                'jours_restants' => $joursRestants !== null ? (int) floor($joursRestants) : null,
                'quantite' => (float) $besoin,
                'conditionnements' => $conditionnements,
                'cout' => (int) round($besoin * $p->prix_achat),
                'en_commande' => (float) ($enCommande[$p->id] ?? 0),
                'urgence' => $p->stock <= 0 ? 'rupture' : ($joursRestants !== null && $joursRestants < 3 ? 'urgent' : 'bientot'),
            ];
        })->filter()->sortBy(fn ($s) => $s['jours_restants'] ?? -1)->values();
    }

    /** Produits en stock sans aucune vente depuis 60 jours. */
    public function dormants(): Collection
    {
        $vendusRecemment = $this->unitesVendues(now()->subDays(self::JOURS_DORMANT))->keys();

        return Produit::stockables()->where('actif', true)->where('stock', '>', 0)->whereNotIn('id', $vendusRecemment)
            ->orderByRaw('stock * prix_achat DESC')->get()
            ->map(fn (Produit $p) => ['produit' => $p, 'valeur' => (int) round($p->stock * $p->prix_achat),
                'derniere_vente' => DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
                    ->where('lignes_vente.produit_id', $p->id)->where('ventes.statut', 'validee')->max('ventes.date_vente')]);
    }

    /** Unités de base vendues depuis une date, par produit (cartons convertis, retours déduits). */
    private function unitesVendues(\Carbon\Carbon $depuis): Collection
    {
        return DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')
            ->where('ventes.date_vente', '>=', $depuis)
            ->groupBy('lignes_vente.produit_id')
            ->selectRaw('lignes_vente.produit_id, SUM(lignes_vente.quantite * lignes_vente.facteur) as unites')
            ->pluck('unites', 'produit_id');
    }
}
