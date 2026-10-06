<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Support\CodeBarre;
use Illuminate\Http\Request;

/** Étiquettes prix + code-barres à imprimer sur planches A4 autocollantes. */
class EtiquetteController extends Controller
{
    /** Planches courantes : colonnes × lignes par page A4. */
    public const FORMATS = [
        '24' => ['3 × 8 (70 × 37 mm)', 3, 8],
        '40' => ['4 × 10 (52 × 29 mm)', 4, 10],
        '65' => ['5 × 13 (38 × 21 mm)', 5, 13],
    ];

    public function index(Request $request)
    {
        $produits = Produit::where('actif', true)->recherche($request->q)
            ->when($request->categorie_id, fn ($q, $c) => $q->where('categorie_id', $c))
            ->orderBy('designation')->limit(300)->get();

        return view('produits.etiquettes', [
            'produits' => $produits,
            'categories' => Categorie::orderBy('nom')->get(),
            'formats' => self::FORMATS,
        ]);
    }

    public function imprimer(Request $request)
    {
        $d = $request->validate([
            'quantites' => ['required', 'array'],
            'quantites.*' => ['nullable', 'integer', 'min:0', 'max:500'],
            'format' => ['required', 'in:'.implode(',', array_keys(self::FORMATS))],
            'prix' => ['nullable', 'boolean'],
        ]);
        $quantites = array_filter($d['quantites'], fn ($q) => (int) $q > 0);
        if (! $quantites) {
            return back()->with('erreur', 'Indiquez le nombre d\'étiquettes pour au moins un produit.');
        }

        $produits = Produit::whereIn('id', array_keys($quantites))->orderBy('designation')->get();
        // Un produit sans code-barres reçoit un code interne (EAN-13 préfixe 2), réutilisé ensuite à la caisse
        $nouveaux = 0;
        foreach ($produits->whereNull('code_barre') as $p) {
            $p->update(['code_barre' => CodeBarre::ean13Interne($p->id)]);
            $nouveaux++;
        }
        if ($nouveaux) {
            JournalActivite::noter('produit', "Code-barres interne attribué à {$nouveaux} produit(s)");
        }

        $etiquettes = $produits->flatMap(fn (Produit $p) => array_fill(0, (int) $quantites[$p->id], $p));
        [, $colonnes, $lignes] = self::FORMATS[$d['format']];

        return view('produits.etiquettes-impression', [
            'etiquettes' => $etiquettes, 'colonnes' => $colonnes, 'lignes' => $lignes,
            'avecPrix' => $request->boolean('prix', true), 'boutique' => boutique(),
            'svg' => $produits->mapWithKeys(fn ($p) => [$p->id => CodeBarre::svg($p->code_barre)]),
        ]);
    }
}
