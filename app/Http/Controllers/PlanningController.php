<?php

namespace App\Http\Controllers;

use App\Services\PlanningEquipe;
use Carbon\Carbon;
use Illuminate\Http\Request;

/** Planning des équipes (semaine par semaine). */
class PlanningController extends Controller
{
    private function lundi(Request $request): Carbon
    {
        return ($request->date('semaine') ?? now())->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public function index(Request $request, PlanningEquipe $planning)
    {
        $lundi = $this->lundi($request);
        $gerant = $request->user()->aPermission('utilisateurs.gerer');
        $grille = $planning->semaine($lundi);
        $employes = $gerant ? $planning->employes() : collect([$request->user()]);

        return view('equipe.planning', [
            'lundi' => $lundi, 'gerant' => $gerant, 'employes' => $employes, 'grille' => $grille,
            'couverture' => $gerant ? $planning->couverture($lundi, $grille) : [],
            'ecarts' => $gerant ? $planning->ecarts($lundi, $grille) : array_values(array_filter($planning->ecarts($lundi, $grille), fn ($e) => $e['user_id'] === $request->user()->id)),
        ]);
    }

    public function store(Request $request, PlanningEquipe $planning)
    {
        $d = $request->validate(['semaine' => ['required', 'date'], 'horaires' => ['nullable', 'array']]);
        $lundi = Carbon::parse($d['semaine'])->startOfWeek(Carbon::MONDAY);
        $n = $planning->enregistrer($lundi, $d['horaires'] ?? [], $request->user());

        return redirect()->route('equipe.planning', ['semaine' => $lundi->toDateString()])->with('succes', "Planning enregistré ({$n} horaire(s)).");
    }

    public function copier(Request $request, PlanningEquipe $planning)
    {
        $lundi = $this->lundi($request);
        $n = $planning->copierSemainePrecedente($lundi);

        return redirect()->route('equipe.planning', ['semaine' => $lundi->toDateString()])
            ->with('succes', $n ? "{$n} horaire(s) recopié(s) de la semaine précédente." : 'Rien à recopier (semaine précédente vide ou déjà planifiée).');
    }
}
