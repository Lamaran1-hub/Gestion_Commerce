<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Promotions à durée limitée (produit, catégorie ou toute la boutique). */
class PromotionController extends Controller
{
    public function index()
    {
        return view('promotions.index', [
            'promotions' => Promotion::with(['produit', 'categorie'])->orderByDesc('actif')->latest('fin')->paginate(25),
            'produits' => Produit::where('actif', true)->orderBy('designation')->get(['id', 'designation', 'prix_vente']),
            'categories' => Categorie::orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request)
    {
        if ($request->type === 'prix') {
            $request->merge(['valeur' => montant_saisi($request->valeur)]);
        } else {
            $request->merge(['valeur' => str_replace(',', '.', (string) $request->valeur)]);
        }
        $d = $request->validate([
            'nom' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['pourcentage', 'prix'])],
            'valeur' => ['required', 'numeric', 'gt:0', $request->type === 'pourcentage' ? 'max:90' : 'min:1'],
            'portee' => ['required', Rule::in(['produit', 'categorie', 'tout'])],
            'produit_id' => ['nullable', 'required_if:portee,produit', Rule::exists('produits', 'id')->where('boutique_id', boutique()->id)],
            'categorie_id' => ['nullable', 'required_if:portee,categorie', Rule::exists('categories', 'id')->where('boutique_id', boutique()->id)],
            'debut' => ['required', 'date'],
            'fin' => ['required', 'date', 'after_or_equal:debut', 'after_or_equal:today'],
        ], [
            'valeur.max' => 'Une réduction ne peut pas dépasser 90 %.',
            'fin.after_or_equal' => 'La date de fin doit être aujourd\'hui ou plus tard, et après la date de début.',
            'produit_id.required_if' => 'Choisissez le produit.', 'categorie_id.required_if' => 'Choisissez la catégorie.',
        ]);
        if ($d['type'] === 'prix' && $d['portee'] !== 'produit') {
            throw ValidationException::withMessages(['type' => 'Un prix promotionnel fixe ne s\'applique qu\'à un produit ; pour une catégorie, utilisez un pourcentage.']);
        }
        // Sur un produit précis, on vérifie tout de suite le prix obtenu
        if ($d['portee'] === 'produit') {
            $p = Produit::findOrFail($d['produit_id']);
            $prix = $d['type'] === 'prix' ? (int) $d['valeur'] : (int) round($p->prix_vente * (1 - $d['valeur'] / 100));
            if ($prix >= $p->prix_vente) {
                throw ValidationException::withMessages(['valeur' => 'Le prix promotionnel doit être inférieur au prix normal ('.gnf($p->prix_vente).').']);
            }
            if (! boutique()->vente_a_perte && $p->prix_achat > 0 && $prix < $p->prix_achat) {
                throw ValidationException::withMessages(['valeur' => 'Avec cette promotion, « '.$p->designation.' » serait vendu '.gnf($prix)
                    .', sous son prix d\'achat ('.gnf($p->prix_achat).'). La vente à perte est désactivée dans les paramètres.']);
            }
        }

        $promo = Promotion::create([
            'nom' => $d['nom'], 'type' => $d['type'], 'valeur' => $d['valeur'],
            'produit_id' => $d['portee'] === 'produit' ? $d['produit_id'] : null,
            'categorie_id' => $d['portee'] === 'categorie' ? $d['categorie_id'] : null,
            'debut' => $d['debut'], 'fin' => $d['fin'], 'actif' => true, 'user_id' => $request->user()->id,
        ]);
        JournalActivite::noter('promotion', "Promotion « {$promo->nom} » ({$promo->libelleReduction()}) du "
            .$promo->debut->format('d/m/Y').' au '.$promo->fin->format('d/m/Y'));

        return back()->with('succes', "Promotion « {$promo->nom} » enregistrée.");
    }

    /** Arrêter une promotion avant sa fin (ou la relancer). */
    public function basculer(Promotion $promotion)
    {
        $promotion->update(['actif' => ! $promotion->actif]);
        JournalActivite::noter('promotion', ($promotion->actif ? 'Reprise' : 'Arrêt')." de la promotion « {$promotion->nom} »");

        return back()->with('succes', "Promotion « {$promotion->nom} » ".($promotion->actif ? 'relancée.' : 'arrêtée.'));
    }

    public function destroy(Promotion $promotion)
    {
        $promotion->delete();
        JournalActivite::noter('promotion', "Suppression de la promotion « {$promotion->nom} »");

        return back()->with('succes', 'Promotion supprimée.');
    }
}
