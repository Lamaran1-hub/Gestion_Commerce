<?php

namespace App\Http\Controllers;

use App\Exceptions\OperationRefusee;
use App\Models\Categorie;
use App\Models\Fournisseur;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Services\StockService;
use App\Support\Tableau;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class ProduitController extends Controller
{
    public function index(Request $request)
    {
        $produits = $this->filtrer($request)->with(['categorie', 'fournisseur'])->orderBy('designation')->paginate(25)->withQueryString();

        return view('produits.index', [
            'produits' => $produits,
            'categories' => Categorie::orderBy('nom')->get(),
            'valeurStock' => (int) Produit::where('stock', '>', 0)->sum(DB::raw('stock * prix_achat')),
            'nbAlertes' => Produit::where('actif', true)->enAlerte()->count(),
        ]);
    }

    public function export(Request $request, string $format)
    {
        $voirAchat = $request->user()->aPermission('produits.prix_achat');
        $lignes = $this->filtrer($request)->with(['categorie', 'fournisseur'])->orderBy('designation')->get()->map(fn (Produit $p) => [
            'designation' => $p->designation,
            'code' => $p->code_barre,
            'categorie' => $p->categorie?->nom,
            'fournisseur' => $p->fournisseur?->nom,
            'prix_achat' => $p->prix_achat,
            'prix_vente' => $p->prix_vente,
            'stock' => $p->stock,
            'unite' => $p->unite,
            'valeur' => $p->valeurStock(),
        ])->all();

        $colonnes = ['designation' => 'Désignation', 'code' => 'Code-barres', 'categorie' => 'Catégorie', 'fournisseur' => 'Fournisseur',
            'prix_achat' => "Prix d'achat", 'prix_vente' => 'Prix de vente', 'stock' => 'Stock', 'unite' => 'Unité', 'valeur' => 'Valeur du stock'];
        if (! $voirAchat) {
            unset($colonnes['prix_achat'], $colonnes['valeur']);
        }

        return Tableau::telecharger($format, 'État du stock', $colonnes, $lignes, ['prix_achat', 'prix_vente', 'valeur'],
            totaux: $voirAchat ? ['valeur' => array_sum(array_column($lignes, 'valeur'))] : []);
    }

    public function create()
    {
        $this->verifierLimitePlan();

        return view('produits.form', $this->donneesFormulaire(new Produit(['unite' => 'pièce', 'actif' => true])));
    }

    public function store(Request $request, StockService $stock)
    {
        $this->verifierLimitePlan();
        $d = $this->valider($request);
        $d['image'] = $request->file('image')?->store('boutiques/'.boutique()->id.'/produits', 'public');

        $produit = DB::transaction(function () use ($d, $stock) {
            $produit = Produit::create($d);
            if (($d['stock_initial'] ?? 0) > 0) {
                $stock->mouvement($produit, 'stock_initial', (float) $d['stock_initial'], null, 'Stock initial');
            }

            return $produit;
        });
        JournalActivite::noter('produit', "Création du produit {$produit->designation}");

        return redirect()->route($request->boolean('continuer') ? 'produits.create' : 'produits.index')
            ->with('succes', "Produit « {$produit->designation} » ajouté.");
    }

    public function show(Produit $produit)
    {
        return view('produits.show', [
            'produit' => $produit->load(['categorie', 'fournisseur']),
            'mouvements' => $produit->mouvements()->with('auteur')->latest('id')->paginate(20),
            // Changements de prix ; le prix d'achat seulement pour ceux qui ont le droit de le voir
            'historiquePrix' => $produit->historiquePrix()->with('auteur')
                ->when(! auth()->user()->aPermission('produits.prix_achat'), fn ($q) => $q->where('champ', '!=', 'prix_achat'))->limit(15)->get(),
        ]);
    }

    public function edit(Produit $produit)
    {
        return view('produits.form', $this->donneesFormulaire($produit));
    }

    public function update(Request $request, Produit $produit)
    {
        $d = $this->valider($request, $produit);
        unset($d['stock_initial']);
        if ($request->hasFile('image')) {
            if ($produit->image) {
                Storage::disk('public')->delete($produit->image);
            }
            $d['image'] = $request->file('image')->store('boutiques/'.boutique()->id.'/produits', 'public');
        } else {
            unset($d['image']);
        }
        $produit->update($d);

        return redirect()->route('produits.index')->with('succes', "Produit « {$produit->designation} » mis à jour.");
    }

    public function destroy(Produit $produit)
    {
        // Un produit encore en rayon ne disparaît pas : le stock doit d'abord être justifié (inventaire)
        if ($produit->stock > 0) {
            return back()->with('erreur', "« {$produit->designation} » a encore ".qte($produit->stock)." {$produit->unite} en stock. "
                .'Mettez le stock à zéro par un inventaire (avec le motif : perte, casse…) ou désactivez simplement le produit.');
        }
        $produit->delete(); // suppression douce : l'historique des ventes reste intact
        JournalActivite::noter('produit', "Suppression du produit {$produit->designation}");

        return redirect()->route('produits.index')->with('succes', "Produit « {$produit->designation} » supprimé.");
    }

    private function valider(Request $request, ?Produit $produit = null): array
    {
        $request->merge([
            'prix_achat' => montant_saisi($request->prix_achat),
            'prix_vente' => montant_saisi($request->prix_vente),
            'prix_gros' => $request->filled('prix_gros') ? montant_saisi($request->prix_gros) : null,
            'quantite_gros' => $request->filled('quantite_gros') ? $request->quantite_gros : null,
            'conditionnement' => trim((string) $request->conditionnement) ?: null,
            'qte_conditionnement' => $request->filled('qte_conditionnement') ? $request->qte_conditionnement : null,
            'prix_conditionnement' => $request->filled('prix_conditionnement') ? montant_saisi($request->prix_conditionnement) : null,
            'code_barre' => $request->code_barre ?: null,
        ]);

        $d = $request->validate([
            'designation' => ['required', 'string', 'max:150'],
            'code_barre' => ['nullable', 'string', 'max:60',
                Rule::unique('produits')->where('boutique_id', boutique()->id)->whereNull('deleted_at')->ignore($produit?->id)],
            'categorie_id' => ['nullable', Rule::exists('categories', 'id')->where('boutique_id', boutique()->id)],
            'fournisseur_id' => ['nullable', Rule::exists('fournisseurs', 'id')->where('boutique_id', boutique()->id)],
            'unite' => ['required', 'string', 'max:30'],
            'prix_achat' => ['required', 'integer', 'min:0'],
            'prix_vente' => ['required', 'integer', 'min:0'],
            // Le prix de gros est plus bas que le prix de détail ; sans quantité, il ne vaut que pour les clients grossistes
            'prix_gros' => ['nullable', 'integer', 'min:1', 'lt:prix_vente'],
            'quantite_gros' => ['nullable', 'numeric', 'gt:1'],
            // Conditionnement : un nom (carton, casier…) et le nombre d'unités qu'il contient
            'conditionnement' => ['nullable', 'string', 'max:30'],
            'qte_conditionnement' => ['nullable', 'required_with:conditionnement', 'numeric', 'gt:1'],
            'prix_conditionnement' => ['nullable', 'integer', 'min:1'],
            'seuil_alerte' => ['nullable', 'numeric', 'min:0'],
            'garantie_mois' => ['nullable', 'integer', 'min:1', 'max:120'],
            'stock_initial' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ]);
        if (! boutique()->vente_a_perte && $d['prix_achat'] > 0 && $d['prix_vente'] < $d['prix_achat']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['prix_vente' => 'Le prix de vente ('.gnf($d['prix_vente'])
                .') est inférieur au prix d\'achat ('.gnf($d['prix_achat']).'). La vente à perte est désactivée dans les paramètres.']);
        }
        if (! boutique()->vente_a_perte && $d['prix_gros'] && $d['prix_achat'] > 0 && $d['prix_gros'] < $d['prix_achat']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['prix_gros' => 'Le prix de gros ('.gnf($d['prix_gros'])
                .') est inférieur au prix d\'achat ('.gnf($d['prix_achat']).').']);
        }
        if (! $d['prix_gros']) {
            $d['quantite_gros'] = null;
        }
        if (! $d['conditionnement']) {
            $d['qte_conditionnement'] = $d['prix_conditionnement'] = null;
        } elseif ($d['prix_conditionnement']) {
            // Le carton ne peut pas coûter plus cher que ses unités vendues séparément, ni moins que son coût d'achat
            $auDetail = (int) round($d['prix_vente'] * $d['qte_conditionnement']);
            $cout = (int) round($d['prix_achat'] * $d['qte_conditionnement']);
            if ($d['prix_conditionnement'] > $auDetail) {
                throw \Illuminate\Validation\ValidationException::withMessages(['prix_conditionnement' => 'Le prix du '.$d['conditionnement']
                    .' ('.gnf($d['prix_conditionnement']).') dépasse le prix de ses unités au détail ('.gnf($auDetail).').']);
            }
            if (! boutique()->vente_a_perte && $cout > 0 && $d['prix_conditionnement'] < $cout) {
                throw \Illuminate\Validation\ValidationException::withMessages(['prix_conditionnement' => 'Le prix du '.$d['conditionnement']
                    .' ('.gnf($d['prix_conditionnement']).') est inférieur à son coût d\'achat ('.gnf($cout).').']);
            }
        }
        $d['seuil_alerte'] = $d['seuil_alerte'] ?? 0;
        $d['actif'] = $request->boolean('actif', true);
        // TVA du produit : taux normal de la boutique (null), exonéré (0) ou autre taux
        if ($request->has('regime_tva')) {
            $request->validate([
                'regime_tva' => ['required', 'in:normal,exonere,autre'],
                'taux_tva_autre' => ['nullable', 'required_if:regime_tva,autre', 'numeric', 'min:0', 'max:50'],
            ], ['taux_tva_autre.required_if' => 'Indiquez le taux de TVA de ce produit.']);
            $d['taux_tva'] = match ($request->regime_tva) {
                'exonere' => 0,
                'autre' => round((float) $request->taux_tva_autre, 2),
                default => null,
            };
        }
        $d['suivi_serie'] = $request->boolean('suivi_serie');
        if ($request->has('en_vitrine')) {
            $d['en_vitrine'] = $request->boolean('en_vitrine');
        }

        return $d;
    }

    private function donneesFormulaire(Produit $produit): array
    {
        return [
            'produit' => $produit,
            'categories' => Categorie::orderBy('nom')->get(),
            'fournisseurs' => Fournisseur::orderBy('nom')->get(),
        ];
    }

    private function verifierLimitePlan(): void
    {
        boutique()->verifierLimite('produits');
    }

    private function filtrer(Request $request)
    {
        return Produit::query()
            ->recherche($request->q)
            ->when($request->categorie_id, fn ($q) => $q->where('categorie_id', $request->categorie_id))
            ->when($request->etat === 'alerte', fn ($q) => $q->enAlerte())
            ->when($request->etat === 'rupture', fn ($q) => $q->where('stock', '<=', 0))
            ->when($request->etat === 'inactif', fn ($q) => $q->where('actif', false));
    }
}
