<?php

namespace App\Http\Controllers;

use App\Models\Approvisionnement;
use App\Models\PaiementFournisseur;
use App\Models\RetourFournisseur;
use App\Services\RetourFournisseurService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Marchandise renvoyée au fournisseur, depuis la page d'une réception. */
class RetourFournisseurController extends Controller
{
    public function store(Request $request, Approvisionnement $approvisionnement, RetourFournisseurService $service)
    {
        $request->validate([
            'quantites' => ['required', 'array'],
            'quantites.*' => ['nullable', 'numeric', 'min:0'],
            'motif' => ['required', 'string', 'max:120'],
            'motif_autre' => ['nullable', 'string', 'max:120'],
            'mode_remboursement' => ['required', Rule::in([PaiementFournisseur::MODE_AVOIR, ...array_keys(config('gestion.modes_paiement'))])],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['motif.required' => 'Indiquez le motif du retour.']);

        $retour = $service->enregistrer($approvisionnement, $request->quantites, choix_autre('motif'), $request->mode_remboursement,
            $request->note, $request->user());
        session()->flash('bon_retour', route('retours-fournisseur.bon', $retour));

        return back()->with('succes', "Retour {$retour->numero} enregistré : ".gnf($retour->montant).' de marchandise sortie du stock. '
            .match (true) {
                ! $retour->rembourse => 'Montant déduit de ce que vous devez au fournisseur.',
                $retour->mode_remboursement === PaiementFournisseur::MODE_AVOIR => gnf($retour->rembourse).' en avoir chez le fournisseur, à utiliser pour régler une prochaine livraison.',
                default => 'Le fournisseur vous rembourse '.gnf($retour->rembourse).' ('.libelle_mode($retour->mode_remboursement).').',
            });
    }

    /** Bon de retour à faire signer par le livreur ou le fournisseur. */
    public function bon(RetourFournisseur $retour)
    {
        return Pdf::loadView('pdf.bon-retour-fournisseur', [
            'boutique' => boutique(),
            'retour' => $retour->load(['lignes', 'fournisseur', 'approvisionnement', 'auteur']),
        ])->setPaper('a4')->stream('bon-retour-'.$retour->numero.'.pdf');
    }
}
