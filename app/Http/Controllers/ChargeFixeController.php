<?php

namespace App\Http\Controllers;

use App\Models\ChargeFixe;
use App\Models\JournalActivite;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Charges fixes : déclarées une fois, rappelées chaque mois sur la page Dépenses. */
class ChargeFixeController extends Controller
{
    public function store(Request $request)
    {
        $c = ChargeFixe::create($this->valider($request) + ['user_id' => $request->user()->id]);
        JournalActivite::noter('depense', "Charge fixe ajoutée : {$c->motif}, ".gnf($c->montant)." le {$c->jour} de chaque mois");

        return redirect()->to(route('depenses.index').'#charges')->with('succes', "Charge fixe « {$c->motif} » ajoutée : elle sera rappelée chaque mois.");
    }

    public function update(Request $request, ChargeFixe $charge)
    {
        $charge->update($this->valider($request));
        JournalActivite::noter('depense', "Charge fixe modifiée : {$charge->motif}, ".gnf($charge->montant));

        return redirect()->to(route('depenses.index').'#charges')->with('succes', "Charge fixe « {$charge->motif} » mise à jour.");
    }

    /** Une charge déjà payée garde son historique : elle est seulement arrêtée. */
    public function destroy(ChargeFixe $charge)
    {
        if ($charge->paiements()->exists()) {
            $charge->update(['actif' => false]);
        } else {
            $charge->delete();
        }
        JournalActivite::noter('depense', "Charge fixe arrêtée : {$charge->motif}");

        return redirect()->to(route('depenses.index').'#charges')->with('succes', "« {$charge->motif} » n'est plus rappelée. Les paiements déjà faits restent dans les dépenses.");
    }

    private function valider(Request $request): array
    {
        $request->merge(['montant' => montant_saisi($request->montant), 'categorie' => choix_autre('categorie')]);

        return $request->validate([
            'motif' => ['required', 'string', 'max:150'],
            'categorie' => ['nullable', 'string', 'max:60'],
            'montant' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'jour' => ['required', 'integer', 'between:1,28'],
        ], ['jour.between' => 'Choisissez un jour entre le 1 et le 28 (valable tous les mois).']);
    }
}
