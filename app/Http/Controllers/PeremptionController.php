<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Services\Peremption;
use App\Services\StockService;
use Illuminate\Http\Request;

/** Produits qui périment : alerte avant la date, retrait du rayon une fois périmés. */
class PeremptionController extends Controller
{
    public function index(Request $request, Peremption $peremption)
    {
        $jours = (int) $request->integer('jours') ?: (boutique()->alerte_peremption_jours ?: 30);
        $lots = $peremption->lots(min(max($jours, 1), 365));

        return view('stock.peremptions', [
            'jours' => $jours,
            'perimes' => $lots->where('jours', '<', 0)->values(),
            'bientot' => $lots->where('jours', '>=', 0)->values(),
        ]);
    }

    /** Sortie de stock d'une marchandise périmée (perte), tracée dans les mouvements. */
    public function retirer(Request $request, Produit $produit, StockService $stock)
    {
        $d = $request->validate(['quantite' => ['required', 'numeric', 'min:0.01']]);
        $quantite = round((float) $d['quantite'], 2);
        if ($quantite > (float) $produit->stock) {
            throw new OperationRefusee("« {$produit->designation} » : seulement ".qte($produit->stock).' en stock.');
        }
        $stock->mouvement($produit, 'peremption', -$quantite, null, 'Marchandise périmée retirée de la vente');
        $perte = (int) round($quantite * $produit->prix_achat);
        JournalActivite::noter('stock', "Retrait de {$produit->designation} périmé : ".qte($quantite).' '.$produit->unite.' (perte '.gnf($perte).')');

        return back()->with('succes', qte($quantite).' '.$produit->unite.' de « '.$produit->designation.' » retiré(s) du stock. Perte : '.gnf($perte).'.');
    }
}
