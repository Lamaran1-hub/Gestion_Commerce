<?php

namespace App\Http\Controllers;

use App\Models\CommandeFournisseur;
use App\Services\CommandesFournisseur;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Commandes fournisseurs : enregistrées depuis « À commander », suivies jusqu'à la réception. */
class CommandeFournisseurController extends Controller
{
    public function __construct(private CommandesFournisseur $service)
    {
    }

    public function index(Request $request)
    {
        $toutes = $request->vue === 'toutes';

        return view('commandes-fournisseur.index', [
            'commandes' => CommandeFournisseur::with('fournisseur')->withCount('lignes')
                ->when(! $toutes, fn ($q) => $q->enAttente()->orderByRaw('livraison_prevue_le IS NULL')->orderBy('livraison_prevue_le'))
                ->latest('id')->paginate(30)->withQueryString(),
            'toutes' => $toutes,
            'nbEnAttente' => CommandeFournisseur::enAttente()->count(),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'fournisseur_id' => ['nullable', Rule::exists('fournisseurs', 'id')->where('boutique_id', boutique()->id)],
            'quantites' => ['required', 'array'],
            'quantites.*' => ['nullable', 'numeric', 'min:0'],
            'livraison_prevue_le' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $c = $this->service->creer($d['fournisseur_id'] ?? null, $d['quantites'], $d['livraison_prevue_le'] ?? null, $d['note'] ?? null, $request->user());

        return redirect()->route('commandes-fournisseur.show', $c)
            ->with('succes', "Commande {$c->numero} enregistrée. Envoyez-la au fournisseur (WhatsApp ou bon de commande) ; à l'arrivée, cliquez « Réceptionner ».");
    }

    public function show(CommandeFournisseur $commande)
    {
        return view('commandes-fournisseur.show', ['c' => $commande->load(['lignes.produit', 'fournisseur', 'receptions', 'auteur'])]);
    }

    public function pdf(CommandeFournisseur $commande)
    {
        $commande->load(['lignes.produit', 'fournisseur']);
        $lignes = $commande->lignes->filter(fn ($l) => $l->produit)->map(fn ($l) => [
            'produit' => $l->produit, 'quantite' => $l->quantite, 'cout' => (int) round($l->quantite * $l->prix_achat_estime),
        ]);

        return Pdf::loadView('pdf.bon-commande', [
            'lignes' => $lignes, 'boutique' => boutique(), 'fournisseur' => $commande->fournisseur, 'numero' => $commande->numero,
            'date' => $commande->date_commande, 'livraisonPrevue' => $commande->livraison_prevue_le,
        ])->setPaper('a4')->stream("bon-de-commande-{$commande->numero}.pdf");
    }

    public function solder(CommandeFournisseur $commande)
    {
        $this->service->solder($commande);

        return back()->with('succes', "Commande {$commande->numero} : {$commande->fresh()->libelleEtat()}.");
    }
}
