<?php

namespace App\Http\Controllers;

use App\Models\Approvisionnement;
use App\Models\Fournisseur;
use App\Models\Produit;
use App\Services\ApprovisionnementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ApprovisionnementController extends Controller
{
    public function index(Request $request)
    {
        return view('approvisionnements.index', [
            'approvisionnements' => Approvisionnement::with(['fournisseur', 'auteur'])->withCount('lignes')
                ->when($request->fournisseur_id, fn ($q) => $q->where('fournisseur_id', $request->fournisseur_id))
                ->when($request->du, fn ($q) => $q->whereDate('date_appro', '>=', $request->du))
                ->when($request->au, fn ($q) => $q->whereDate('date_appro', '<=', $request->au))
                ->latest('date_appro')->latest('id')->paginate(25)->withQueryString(),
            'fournisseurs' => Fournisseur::orderBy('nom')->get(),
        ]);
    }

    public function create(Request $request)
    {
        return view('approvisionnements.create', [
            'fournisseurs' => Fournisseur::orderBy('nom')->get(),
            'produits' => Produit::where('actif', true)->orderBy('designation')->get(['id', 'designation', 'code_barre', 'prix_achat', 'prix_vente', 'stock', 'unite', 'conditionnement', 'qte_conditionnement', 'fournisseur_id']),
            'produitChoisi' => $request->integer('produit_id') ?: null,
            // Réceptionner une commande : fournisseur et quantités restantes préremplis
            'commande' => $request->integer('commande')
                ? \App\Models\CommandeFournisseur::enAttente()->with('lignes')->find($request->integer('commande')) : null,
        ]);
    }

    public function store(Request $request, ApprovisionnementService $service)
    {
        $d = $request->validate([
            'fournisseur_id' => ['nullable', Rule::exists('fournisseurs', 'id')->where('boutique_id', boutique()->id)],
            'commande_fournisseur_id' => ['nullable', Rule::exists('commandes_fournisseur', 'id')->where('boutique_id', boutique()->id)],
            'date_appro' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'reglement' => ['nullable', Rule::in(['comptant', 'partiel', 'credit'])],
            'montant_paye' => ['nullable', 'required_if:reglement,partiel'],
            'mode_reglement' => ['nullable', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference_reglement' => ['nullable', 'string', 'max:120'],
            'echeance' => ['nullable', 'date', 'after_or_equal:date_appro'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'lignes.*.prix_achat_unitaire' => ['required'],
            'lignes.*.prix_vente' => ['nullable'],
            'lignes.*.conditionnement' => ['nullable', 'boolean'],
            'lignes.*.date_peremption' => ['nullable', 'date', 'after:date_appro'],
        ], ['lignes.required' => 'Ajoutez au moins un produit reçu.']);

        $d['lignes'] = array_map(fn ($l) => [
            'produit_id' => $l['produit_id'],
            'quantite' => $l['quantite'],
            'prix_achat_unitaire' => montant_saisi($l['prix_achat_unitaire']),
            'prix_vente' => isset($l['prix_vente']) && $l['prix_vente'] !== '' ? montant_saisi($l['prix_vente']) : null,
            'conditionnement' => ! empty($l['conditionnement']),
        ], $d['lignes']);

        if (isset($d['montant_paye'])) {
            $d['montant_paye'] = montant_saisi($d['montant_paye']);
        }
        $appro = $service->creer($d);

        return redirect()->route('approvisionnements.show', $appro)->with('succes', "Approvisionnement {$appro->numero} enregistré : le stock est à jour.");
    }

    public function show(Approvisionnement $approvisionnement)
    {
        return view('approvisionnements.show', ['appro' => $approvisionnement->load(['lignes.produit', 'fournisseur', 'auteur', 'paiements.auteur', 'retours.lignes', 'retours.auteur'])]);
    }
}
