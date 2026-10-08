<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\JournalActivite;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function index(Request $request)
    {
        $mouvements = MouvementStock::with(['produit', 'auteur'])
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->when($request->produit_id, fn ($q) => $q->where('produit_id', $request->produit_id))
            ->when($request->du, fn ($q) => $q->whereDate('created_at', '>=', $request->du))
            ->when($request->au, fn ($q) => $q->whereDate('created_at', '<=', $request->au))
            ->latest('id')->paginate(30)->withQueryString();

        return view('stock.mouvements', ['mouvements' => $mouvements, 'types' => MouvementStock::LIBELLES]);
    }

    /** Inventaire physique : saisie des quantités comptées. */
    public function inventaire(Request $request)
    {
        return view('stock.inventaire', [
            'produits' => Produit::stockables()->where('actif', true)->recherche($request->q)
                ->when($request->categorie_id, fn ($q) => $q->where('categorie_id', $request->categorie_id))
                ->orderBy('designation')->paginate(50)->withQueryString(),
            'categories' => Categorie::orderBy('nom')->get(),
        ]);
    }

    public function ajuster(Request $request, StockService $stock)
    {
        $request->validate([
            'comptes' => ['required', 'array'],
            'comptes.*' => ['nullable', 'numeric', 'min:0'],
            'motif' => ['nullable', 'string', 'max:200'],
            'motif_autre' => ['nullable', 'string', 'max:150'],
        ]);
        $motif = choix_autre('motif');
        $motif = ! $motif || $motif === 'Inventaire périodique' ? 'Inventaire du '.now()->format('d/m/Y') : $motif;

        $nb = DB::transaction(function () use ($request, $stock, $motif) {
            $nb = 0;
            $comptes = collect($request->comptes)->filter(fn ($v) => $v !== null && $v !== '');
            foreach (Produit::stockables()->whereIn('id', $comptes->keys())->get() as $produit) {
                if ($stock->ajuster($produit, (float) $comptes[$produit->id], $motif)) {
                    $nb++;
                }
            }

            return $nb;
        });
        JournalActivite::noter('inventaire', "Inventaire : {$nb} produit(s) ajusté(s)");

        return back()->with('succes', $nb ? "{$nb} produit(s) ajusté(s). Les écarts sont visibles dans les mouvements de stock." : 'Aucun écart : le stock était déjà juste.');
    }
}
