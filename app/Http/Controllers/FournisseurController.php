<?php

namespace App\Http\Controllers;

use App\Models\Fournisseur;
use Illuminate\Http\Request;

class FournisseurController extends Controller
{
    public function index(Request $request)
    {
        return view('fournisseurs.index', [
            'fournisseurs' => Fournisseur::withCount('produits')->withSum('approvisionnements as total_appro', 'total')
                ->when($request->q, fn ($q) => $q->where(fn ($s) => $s->where('nom', 'like', "%{$request->q}%")->orWhere('telephone', 'like', "%{$request->q}%")))
                ->orderBy('nom')->paginate(25)->withQueryString(),
        ]);
    }

    public function create()
    {
        return view('fournisseurs.form', ['fournisseur' => new Fournisseur]);
    }

    public function store(Request $request)
    {
        $f = Fournisseur::create($this->valider($request));
        if ($request->expectsJson()) {
            return response()->json(['id' => $f->id, 'nom' => $f->nom]);
        }

        return redirect()->route('fournisseurs.index')->with('succes', "Fournisseur {$f->nom} ajouté.");
    }

    public function edit(Fournisseur $fournisseur)
    {
        return view('fournisseurs.form', compact('fournisseur'));
    }

    public function update(Request $request, Fournisseur $fournisseur)
    {
        $fournisseur->update($this->valider($request));

        return redirect()->route('fournisseurs.index')->with('succes', 'Fournisseur mis à jour.');
    }

    public function destroy(Fournisseur $fournisseur)
    {
        $fournisseur->delete();

        return back()->with('succes', 'Fournisseur supprimé.');
    }

    private function valider(Request $request): array
    {
        return $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'contact' => ['nullable', 'string', 'max:120'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:200'],
        ]);
    }
}
