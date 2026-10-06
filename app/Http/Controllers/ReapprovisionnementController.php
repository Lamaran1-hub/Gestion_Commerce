<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use App\Models\Produit;
use App\Services\Reapprovisionnement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** « Quoi commander ? » : suggestions, bons de commande fournisseurs, produits dormants. */
class ReapprovisionnementController extends Controller
{
    public function index(Reapprovisionnement $reappro)
    {
        $suggestions = $reappro->suggestions();

        return view('stock.a-commander', [
            'parFournisseur' => $suggestions->groupBy(fn ($s) => $s['produit']->fournisseur_id ?: 0),
            'fournisseurs' => Fournisseur::whereIn('id', $suggestions->pluck('produit.fournisseur_id')->filter())->get()->keyBy('id'),
            'totalCout' => $suggestions->sum('cout'),
            'nbRuptures' => $suggestions->where('urgence', 'rupture')->count(),
            'dormants' => $reappro->dormants(),
            'couverture' => boutique()->couverture_stock_jours ?: 14,
        ]);
    }

    /** Bon de commande PDF pour un fournisseur, avec les quantités ajustées par le commerçant. */
    public function bonCommande(Request $request)
    {
        $d = $request->validate([
            'fournisseur_id' => ['nullable', 'integer'],
            'quantites' => ['required', 'array'],
            'quantites.*' => ['nullable', 'numeric', 'min:0'],
        ]);
        $quantites = array_filter($d['quantites'], fn ($q) => (float) $q > 0);
        abort_if(! $quantites, 422, 'Aucune quantité à commander.');

        $produits = Produit::whereIn('id', array_keys($quantites))->get();
        $lignes = $produits->map(fn (Produit $p) => [
            'produit' => $p, 'quantite' => (float) $quantites[$p->id],
            'cout' => (int) round((float) $quantites[$p->id] * $p->prix_achat),
        ]);

        return Pdf::loadView('pdf.bon-commande', [
            'lignes' => $lignes, 'boutique' => boutique(),
            'fournisseur' => ! empty($d['fournisseur_id']) ?Fournisseur::find($d['fournisseur_id']) : null,
            'numero' => 'BC-'.now()->format('ymd-His'),
        ])->setPaper('a4')->stream('bon-de-commande.pdf');
    }
}
