<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\ComposantKit;
use App\Models\HistoriquePrix;
use App\Models\JournalActivite;
use App\Models\MouvementStock;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Kits (packs composés) : « Pack rentrée » = 1 sac + 5 cahiers + 2 stylos.
 *
 * Le kit se vend comme un produit, mais il n'a pas de stock propre : vendre un kit sort ses composants,
 * l'annuler ou le reprendre les remet. Son stock affiché est le nombre de kits complets qu'on peut former
 * avec les composants, et son coût d'achat est la somme de leurs coûts (marges justes).
 */
class Kits
{
    /** Opérations possibles sur un kit : tout le reste (réception, inventaire, transfert…) se fait sur ses composants. */
    public const TYPES = ['vente', 'annulation_vente', 'retour_client'];

    public const MAX_COMPOSANTS = 20;

    /**
     * Remplace la composition du kit, puis recalcule son coût et son stock.
     *
     * @param  array<int, float>  $composants  [produit_id => quantité]
     */
    public function composer(Produit $kit, array $composants): void
    {
        $composants = array_filter($composants, fn ($q) => $q > 0);
        if (! $composants) {
            throw new OperationRefusee('Un kit doit contenir au moins un produit.');
        }
        if (count($composants) > self::MAX_COMPOSANTS) {
            throw new OperationRefusee('Un kit contient au plus '.self::MAX_COMPOSANTS.' produits différents.');
        }
        $produits = Produit::whereIn('id', array_keys($composants))->get()->keyBy('id');
        foreach (array_keys($composants) as $id) {
            $p = $produits[$id] ?? throw new OperationRefusee('Un produit du kit est introuvable.');
            if ($p->id === $kit->id || $p->est_kit) {
                throw new OperationRefusee("« {$p->designation} » est lui-même un kit : un kit se compose de produits simples.");
            }
            if ($p->suivi_serie) {
                throw new OperationRefusee("« {$p->designation} » est suivi par numéro de série : vendez-le à part, pas dans un kit.");
            }
        }

        DB::transaction(function () use ($kit, $composants) {
            ComposantKit::where('kit_id', $kit->id)->whereNotIn('composant_id', array_keys($composants))->delete();
            foreach ($composants as $id => $q) {
                ComposantKit::updateOrCreate(['kit_id' => $kit->id, 'composant_id' => $id], ['quantite' => round($q, 2)]);
            }
            $this->recalculer([$kit->id]);
        });
    }

    /** Un kit redevient un produit simple : plus de composition, stock à zéro (il sera approvisionné normalement). */
    public function defaire(Produit $kit): void
    {
        ComposantKit::where('kit_id', $kit->id)->delete();
        Produit::whereKey($kit->id)->update(['stock' => 0]);
    }

    /** Vente, annulation ou retour d'un kit : le mouvement porte sur chacun de ses composants. */
    public function mouvement(Produit $kit, string $type, float $quantite, ?Model $reference, ?string $motif, bool $forcer): MouvementStock
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new OperationRefusee("« {$kit->designation} » est un kit : son stock est celui de ses composants. "
                .'Réceptionnez, inventoriez ou transférez les produits qui le composent.');
        }
        $composants = ComposantKit::with('composant')->where('kit_id', $kit->id)->get();
        if ($composants->isEmpty()) {
            throw new OperationRefusee("Le kit « {$kit->designation} » n'a pas de composition : complétez sa fiche.");
        }
        $dernier = null;
        foreach ($composants as $c) {
            $dernier = app(StockService::class)->mouvement($c->composant, $type, round($quantite * $c->quantite, 2), $reference,
                trim(($motif ?? '').' — kit '.$kit->designation, ' —'), $forcer);
        }

        return $dernier;
    }

    /** Un composant a bougé : les kits qui le contiennent changent de stock. */
    public function apresMouvement(int $produitId): void
    {
        $kits = ComposantKit::withoutGlobalScopes()->where('composant_id', $produitId)->pluck('kit_id')->all();
        if ($kits) {
            $this->recalculer($kits, coutAussi: false);
        }
    }

    /** Le coût d'un composant a changé : le coût des kits qui le contiennent suit. */
    public function apresChangementCout(int $produitId): void
    {
        $kits = ComposantKit::withoutGlobalScopes()->where('composant_id', $produitId)->pluck('kit_id')->all();
        if ($kits) {
            HistoriquePrix::depuis('Coût des composants', fn () => $this->recalculer($kits));
        }
    }

    /**
     * Stock d'un kit = nombre de kits complets formables ; coût = somme des coûts des composants.
     *
     * @param  array<int, int>  $kitIds
     */
    public function recalculer(array $kitIds, bool $coutAussi = true): void
    {
        $lignes = DB::table('composants_kit')->join('produits', 'produits.id', '=', 'composants_kit.composant_id')
            ->whereIn('composants_kit.kit_id', $kitIds)
            ->get(['composants_kit.kit_id', 'composants_kit.quantite', 'produits.stock', 'produits.prix_achat', 'produits.deleted_at', 'produits.actif'])
            ->groupBy('kit_id');
        foreach ($kitIds as $id) {
            $l = $lignes[$id] ?? collect();
            // Un composant supprimé ou désactivé : le kit ne peut plus être formé
            $stock = $l->isEmpty() || $l->contains(fn ($c) => $c->deleted_at || ! $c->actif) ? 0
                : max(0, (int) floor($l->min(fn ($c) => (float) $c->stock / (float) $c->quantite) + 1e-9));
            DB::table('produits')->where('id', $id)->update(['stock' => $stock]);
            if ($coutAussi) {
                $kit = Produit::withoutGlobalScopes()->withTrashed()->find($id);
                $cout = (int) round($l->sum(fn ($c) => (int) $c->prix_achat * (float) $c->quantite));
                if ($kit && $kit->prix_achat !== $cout) {
                    $kit->forceFill(['prix_achat' => $cout])->save();   // noté dans l'historique des prix
                }
            }
        }
    }

    /** Nom des kits qui contiennent ce produit (affiché sur sa fiche, et pour empêcher sa suppression). */
    public function kitsContenant(Produit $produit): \Illuminate\Support\Collection
    {
        return ComposantKit::with('kit')->where('composant_id', $produit->id)->get()->pluck('kit')->filter(fn ($k) => $k && ! $k->trashed());
    }

    public static function noter(Produit $kit): void
    {
        $kit->loadMissing('composants.composant');
        JournalActivite::noter('produit', "Composition du kit {$kit->designation} : "
            .$kit->composants->map(fn ($c) => qte($c->quantite).' × '.$c->composant?->designation)->implode(', '));
    }
}
