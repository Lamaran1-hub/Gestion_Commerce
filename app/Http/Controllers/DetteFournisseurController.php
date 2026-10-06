<?php

namespace App\Http\Controllers;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\PaiementFournisseur;
use App\Services\DetteFournisseurService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Ce que la boutique doit à ses fournisseurs, et leurs règlements. */
class DetteFournisseurController extends Controller
{
    public function index()
    {
        $dettes = Approvisionnement::avecReste()->whereNotNull('fournisseur_id')->with('fournisseur')
            ->orderBy('echeance')->orderBy('date_appro')->get()->groupBy('fournisseur_id')
            ->map(fn ($receptions) => [
                'fournisseur' => $receptions->first()->fournisseur,
                'du' => $receptions->sum(fn ($a) => $a->resteAPayer()),
                'receptions' => $receptions,
                'en_retard' => $receptions->filter->enRetard()->sum(fn ($a) => $a->resteAPayer()),
                'prochaine_echeance' => $receptions->pluck('echeance')->filter()->min(),
            ])->sortByDesc('en_retard')->values();

        // Avoirs accordés par les fournisseurs après un retour (utilisables pour régler)
        $avoirs = \App\Models\PaiementFournisseur::where('mode', \App\Models\PaiementFournisseur::MODE_AVOIR)->whereNotNull('fournisseur_id')
            ->groupBy('fournisseur_id')->selectRaw('fournisseur_id, -SUM(montant) as solde')->pluck('solde', 'fournisseur_id')
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0);

        return view('fournisseurs.dettes', [
            'dettes' => $dettes,
            'avoirs' => $avoirs,
            'fournisseursAvoir' => \App\Models\Fournisseur::whereIn('id', $avoirs->keys())->orderBy('nom')->get(),
            'total' => $dettes->sum('du'),
            'totalRetard' => $dettes->sum('en_retard'),
        ]);
    }

    public function regler(Request $request, Fournisseur $fournisseur, DetteFournisseurService $service)
    {
        $request->merge(['montant' => montant_saisi($request->montant)]);
        $d = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in([...array_keys(config('gestion.modes_paiement')), \App\Models\PaiementFournisseur::MODE_AVOIR])],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $montant = $service->regler($fournisseur, $d['montant'], $d['mode'], $d['reference'] ?? null, $request->user());

        return back()->with('succes', 'Règlement de '.gnf($montant)." enregistré pour {$fournisseur->nom}. Reste dû : ".gnf($fournisseur->soldeDu()).'.');
    }
}
