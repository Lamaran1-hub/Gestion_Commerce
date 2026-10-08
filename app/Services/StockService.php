<?php

namespace App\Services;

use App\Exceptions\OperationRefusee;
use App\Models\MouvementStock;
use App\Models\Produit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'entrée pour modifier le stock. Chaque variation laisse une trace
 * (qui, quand, pourquoi) dans mouvements_stock.
 */
class StockService
{
    public function mouvement(Produit $produit, string $type, float $quantite, ?Model $reference = null, ?string $motif = null, bool $forcer = false): MouvementStock
    {
        return DB::transaction(function () use ($produit, $type, $quantite, $reference, $motif, $forcer) {
            $p = Produit::withTrashed()->whereKey($produit->id)->lockForUpdate()->firstOrFail();
            // Kit : ce sont ses composants qui sortent ou rentrent
            if ($p->est_kit) {
                $m = app(Kits::class)->mouvement($p, $type, $quantite, $reference, $motif, $forcer);
                $produit->setAttribute('stock', Produit::withTrashed()->whereKey($p->id)->value('stock'));

                return $m;
            }
            $nouveau = round($p->stock + $quantite, 2);

            if ($nouveau < 0 && ! $forcer && ! config('gestion.stock_negatif_autorise')) {
                throw new OperationRefusee(sprintf(
                    'Stock insuffisant pour « %s » : %s disponible(s), %s demandé(s).',
                    $p->designation, qte($p->stock), qte(abs($quantite))
                ));
            }

            $p->forceFill(['stock' => $nouveau])->save();
            $produit->setAttribute('stock', $nouveau);
            app(Kits::class)->apresMouvement($p->id);

            return MouvementStock::create([
                'produit_id' => $p->id,
                'type' => $type,
                'quantite' => $quantite,
                'stock_apres' => $nouveau,
                'cout_unitaire' => (int) $p->prix_achat,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'motif' => $motif,
                'user_id' => auth()->id(),
            ]);
        });
    }

    /** Fixe le stock à la quantité comptée lors d'un inventaire. */
    public function ajuster(Produit $produit, float $quantiteComptee, string $motif): ?MouvementStock
    {
        $ecart = round($quantiteComptee - $produit->fresh()->stock, 2);

        return $ecart == 0.0 ? null : $this->mouvement($produit, 'ajustement', $ecart, null, $motif);
    }
}
