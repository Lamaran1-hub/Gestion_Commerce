<?php

namespace App\Http\Controllers;

use App\Models\Vente;
use App\Services\Livraisons;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** Livraisons chez le client : liste, étapes et bon de livraison. */
class LivraisonController extends Controller
{
    public function __construct(private Livraisons $livraisons)
    {
    }

    public function index(Request $request)
    {
        $faites = $request->vue === 'livrees';

        return view('ventes.livraisons', [
            'ventes' => Vente::with('client')
                ->when($faites, fn ($q) => $q->validees()->where('livraison', 'livree')->latest('livree_le'),
                    fn ($q) => $q->aLivrer()->orderByRaw('livraison_prevue_le IS NULL')->orderBy('livraison_prevue_le')->orderBy('date_vente'))
                ->paginate(30)->withQueryString(),
            'faites' => $faites,
            'nbALivrer' => Vente::aLivrer()->count(),
        ]);
    }

    public function programmer(Request $request, Vente $vente)
    {
        $d = $request->validate([
            'livraison_adresse' => ['required', 'string', 'max:255'],
            'livraison_contact' => ['nullable', 'string', 'max:120'],
            'livraison_prevue_le' => ['nullable', 'date', 'after_or_equal:'.$vente->date_vente->toDateString()],
            'livreur' => ['nullable', 'string', 'max:120'],
        ], ['livraison_adresse.required' => 'Indiquez l\'adresse de livraison (quartier, repère…).',
            'livraison_prevue_le.after_or_equal' => 'La livraison ne peut pas être prévue avant la vente.']);
        $this->livraisons->programmer($vente, $d);

        return back()->with('succes', "Livraison de {$vente->numero} enregistrée.");
    }

    public function partir(Request $request, Vente $vente)
    {
        $d = $request->validate(['livreur' => ['required', 'string', 'max:120']], ['livreur.required' => 'Indiquez le nom du livreur.']);
        $this->livraisons->partir($vente, $d['livreur']);

        return back()->with('succes', "{$vente->numero} est en route avec {$d['livreur']}.".($vente->resteAPayer() ? ' Montant à encaisser à la livraison : '.gnf($vente->resteAPayer()).'.' : ''));
    }

    public function livrer(Request $request, Vente $vente)
    {
        $d = $request->validate(['livree_a' => ['required', 'string', 'max:120']], ['livree_a.required' => 'Indiquez qui a réceptionné la marchandise.']);
        $this->livraisons->livrer($vente, $d['livree_a']);

        return back()->with('succes', "{$vente->numero} livrée à {$d['livree_a']}.".($vente->resteAPayer() ? ' Pensez à encaisser '.gnf($vente->resteAPayer()).'.' : ''));
    }

    public function retirer(Vente $vente)
    {
        $this->livraisons->retirer($vente);

        return back()->with('succes', "Livraison retirée : {$vente->numero} est à emporter.");
    }

    /** Bon de livraison sans les prix, à faire signer par la personne qui réceptionne. */
    public function bon(Vente $vente)
    {
        abort_unless($vente->livraison, 404);

        return Pdf::loadView('pdf.bon-livraison', ['vente' => $vente->load(['lignes', 'client']), 'boutique' => boutique()])
            ->setPaper('a4')->stream("bon-livraison-{$vente->numero}.pdf");
    }
}
