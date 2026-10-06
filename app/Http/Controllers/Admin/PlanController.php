<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use Illuminate\Http\Request;

class PlanController extends Controller
{
    public function index()
    {
        return view('admin.plans.index', ['plans' => Plan::withCount('boutiques')->orderBy('prix_mensuel')->get()]);
    }

    public function create()
    {
        return view('admin.plans.form', ['plan' => new Plan(['actif' => true])]);
    }

    public function store(Request $request)
    {
        Plan::create($this->valider($request));

        return redirect()->route('admin.plans.index')->with('succes', 'Formule créée.');
    }

    public function edit(Plan $plan)
    {
        return view('admin.plans.form', compact('plan'));
    }

    public function update(Request $request, Plan $plan)
    {
        $plan->update($this->valider($request));

        return redirect()->route('admin.plans.index')->with('succes', 'Formule mise à jour.');
    }

    public function destroy(Plan $plan)
    {
        if ($plan->boutiques()->exists()) {
            return back()->with('erreur', 'Des boutiques utilisent cette formule : désactivez-la plutôt.');
        }
        $plan->delete();

        return back()->with('succes', 'Formule supprimée.');
    }

    private function valider(Request $request): array
    {
        $request->merge(['prix_mensuel' => montant_saisi($request->prix_mensuel)]);
        $d = $request->validate([
            'nom' => ['required', 'string', 'max:60'],
            'prix_mensuel' => ['required', 'integer', 'min:0'],
            'max_utilisateurs' => ['nullable', 'integer', 'min:1'],
            'max_produits' => ['nullable', 'integer', 'min:1'],
            'max_boutiques' => ['nullable', 'integer', 'min:1'],
            'fonctions' => ['nullable', 'array'],
            'fonctions.*' => [\Illuminate\Validation\Rule::in(array_keys(config('gestion.fonctions')))],
            'description' => ['nullable', 'string', 'max:500'],
        ]);
        $d['actif'] = $request->boolean('actif');
        // « Toutes les fonctions » : null, pour inclure aussi celles ajoutées plus tard au logiciel
        $d['fonctions'] = $request->boolean('toutes_fonctions') ? null : array_values($d['fonctions'] ?? []);

        return $d;
    }
}
