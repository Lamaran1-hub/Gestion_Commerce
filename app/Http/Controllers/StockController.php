<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\JournalActivite;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Services\InventaireTournant;
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
    public function inventaire(Request $request, InventaireTournant $tournant)
    {
        $duJour = $request->boolean('tournant');

        return view('stock.inventaire', [
            'duJour' => $duJour,
            'produits' => $duJour ? $tournant->duJour() : Produit::stockables()->where('actif', true)->recherche($request->q)
                ->when($request->categorie_id, fn ($q) => $q->where('categorie_id', $request->categorie_id))
                ->orderBy('designation')->paginate(50)->withQueryString(),
            'categories' => Categorie::orderBy('nom')->get(),
            'couverture' => $tournant->couverture(),
            'parJour' => $tournant->parJour(),
        ]);
    }

    public function ajuster(Request $request, StockService $stock, InventaireTournant $tournant)
    {
        $request->validate([
            'comptes' => ['required', 'array'],
            'comptes.*' => ['nullable', 'numeric', 'min:0'],
            'theoriques' => ['nullable', 'array'],
            'theoriques.*' => ['nullable', 'numeric'],
            'motif' => ['nullable', 'string', 'max:200'],
            'motif_autre' => ['nullable', 'string', 'max:150'],
        ]);
        $motif = choix_autre('motif');
        $libelle = $request->boolean('tournant') ? 'Comptage du jour' : 'Inventaire';
        $motif = ! $motif || $motif === 'Inventaire périodique' ? $libelle.' du '.now()->format('d/m/Y') : $motif;

        [$nb, $comptes] = DB::transaction(function () use ($request, $stock, $tournant, $motif) {
            $nb = 0;
            $comptes = collect($request->comptes)->filter(fn ($v) => $v !== null && $v !== '');
            $affiches = collect($request->theoriques);
            $produits = Produit::stockables()->whereIn('id', $comptes->keys())->lockForUpdate()->get();
            foreach ($produits as $produit) {
                $affiche = $affiches->get($produit->id);
                if ($stock->ajuster($produit, (float) $comptes[$produit->id], $motif, is_numeric($affiche) ? (float) $affiche : null)) {
                    $nb++;
                }
            }
            $tournant->marquer($produits->pluck('id'));

            return [$nb, $produits->count()];
        });
        JournalActivite::noter('inventaire', "{$libelle} : {$comptes} produit(s) compté(s), {$nb} ajusté(s)");

        $message = $nb ? "{$comptes} produit(s) compté(s), {$nb} ajusté(s). Les écarts sont visibles dans les mouvements de stock."
            : ($comptes ? "{$comptes} produit(s) compté(s) : aucun écart, le stock était juste." : 'Aucune quantité saisie.');

        return back()->with($comptes ? 'succes' : 'erreur', $message);
    }
}
