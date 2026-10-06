<?php

namespace App\Http\Controllers;

use App\Models\Depense;
use App\Models\JournalActivite;
use App\Exceptions\OperationRefusee;
use App\Services\CaisseService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DepenseController extends Controller
{
    public function index(Request $request)
    {
        $du = $request->du ?: now()->startOfMonth()->toDateString();
        $au = $request->au ?: now()->toDateString();
        $requete = Depense::whereDate('date_depense', '>=', $du)->whereDate('date_depense', '<=', $au)
            ->when($request->categorie, fn ($q) => $q->where('categorie', $request->categorie));

        return view('depenses.index', [
            // Dépenses nées d'un versement de commission : gérées depuis la page Commissions
            'issuesDeCommission' => \App\Models\CommissionVersee::whereNotNull('depense_id')->pluck('depense_id')->flip(),
            'depenses' => (clone $requete)->with('auteur')->latest('date_depense')->latest('id')->paginate(25)->withQueryString(),
            'total' => (int) (clone $requete)->sum('montant'),
            'parCategorie' => (clone $requete)->selectRaw("COALESCE(categorie, 'Autre') as cat, SUM(montant) as total")->groupBy('cat')->orderByDesc('total')->pluck('total', 'cat'),
            'du' => $du, 'au' => $au,
            'depenseEdition' => $request->modifier ? Depense::find($request->modifier) : null,
            // Catégories prévues + catégories personnalisées saisies via « Autre »
            'categories' => collect(config('gestion.categories_depense'))
                ->merge(Depense::whereNotNull('categorie')->distinct()->orderBy('categorie')->pluck('categorie'))->unique()->values(),
        ]);
    }

    public function store(Request $request, CaisseService $caisse)
    {
        $donnees = $this->valider($request);
        boutique()->verifierPeriodeOuverte($donnees['date_depense'], 'enregistrer une dépense à cette date');
        // Une dépense en espèces sort du tiroir : la caisse de l'utilisateur doit être ouverte
        if ($donnees['mode'] === 'especes') {
            $caisse->verifierOuverte($request->user());
        }
        $d = Depense::create($donnees + ['user_id' => auth()->id()]);
        JournalActivite::noter('depense', "Dépense « {$d->motif} » de ".gnf($d->montant));

        return back()->with('succes', 'Dépense de '.gnf($d->montant).' enregistrée.');
    }

    public function edit(Depense $depense)
    {
        return redirect()->route('depenses.index', ['modifier' => $depense->id]);
    }

    public function update(Request $request, Depense $depense, CaisseService $caisse)
    {
        $this->verifierModifiable($depense, $caisse);
        $donnees = $this->valider($request);
        boutique()->verifierPeriodeOuverte($donnees['date_depense'], 'déplacer une dépense dans une période clôturée');
        if ($donnees['mode'] === 'especes' && ($depense->mode !== 'especes' || $depense->montant !== $donnees['montant'])) {
            $caisse->verifierOuverte($request->user());
        }
        $depense->update($donnees);
        JournalActivite::noter('depense', "Modification de la dépense « {$depense->motif} » : ".gnf($depense->montant));

        return redirect()->route('depenses.index')->with('succes', 'Dépense modifiée.');
    }

    public function destroy(Depense $depense, CaisseService $caisse)
    {
        $this->verifierModifiable($depense, $caisse);
        $depense->delete();
        JournalActivite::noter('depense', "Suppression de la dépense « {$depense->motif} »");

        return back()->with('succes', 'Dépense supprimée.');
    }

    /**
     * Une dépense en espèces fait partie du rapport Z de la journée où elle a été saisie :
     * une fois cette caisse clôturée, la dépense est figée (sinon le rapport deviendrait faux).
     */
    private function verifierModifiable(Depense $depense, CaisseService $caisse): void
    {
        if (\App\Models\CommissionVersee::where('depense_id', $depense->id)->exists()) {
            throw new OperationRefusee('Cette dépense est le versement d\'une commission : annulez-le depuis la page Commissions (la dépense suivra).');
        }
        boutique()->verifierPeriodeOuverte($depense->date_depense, 'modifier ou supprimer cette dépense');
        if ($depense->mode === 'especes' && $depense->auteur && $caisse->estCloturee($depense->auteur, $depense->created_at)) {
            throw new OperationRefusee('Cette dépense en espèces appartient à une caisse déjà clôturée (le '.$depense->created_at->format('d/m/Y')
                .') : elle ne peut plus être modifiée ni supprimée. Enregistrez une opération de correction si nécessaire.');
        }
    }

    private function valider(Request $request): array
    {
        $request->merge(['montant' => montant_saisi($request->montant), 'categorie' => choix_autre('categorie'), 'mode' => $request->mode ?: 'especes']);

        return $request->validate([
            'motif' => ['required', 'string', 'max:150'],
            'categorie' => ['nullable', 'string', 'max:60'],
            'montant' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'date_depense' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
