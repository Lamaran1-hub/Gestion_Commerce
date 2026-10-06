<?php

namespace App\Http\Controllers;

use App\Models\Categorie;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategorieController extends Controller
{
    public function index()
    {
        return view('categories.index', ['categories' => Categorie::withCount('produits')->orderBy('nom')->get()]);
    }

    public function store(Request $request)
    {
        $categorie = Categorie::create($this->valider($request));
        if ($request->expectsJson()) {
            return response()->json(['id' => $categorie->id, 'nom' => $categorie->nom]);
        }

        return back()->with('succes', 'Catégorie ajoutée.');
    }

    public function update(Request $request, Categorie $categorie)
    {
        $categorie->update($this->valider($request, $categorie));

        return back()->with('succes', 'Catégorie renommée.');
    }

    public function destroy(Categorie $categorie)
    {
        $categorie->delete(); // les produits passent « sans catégorie »

        return back()->with('succes', 'Catégorie supprimée.');
    }

    private function valider(Request $request, ?Categorie $c = null): array
    {
        return $request->validate(['nom' => ['required', 'string', 'max:80',
            Rule::unique('categories')->where('boutique_id', boutique()->id)->ignore($c?->id)]]);
    }
}
