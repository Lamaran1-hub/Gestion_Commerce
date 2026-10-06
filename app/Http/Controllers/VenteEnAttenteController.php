<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\VenteEnAttente;
use App\Services\VenteService;
use Illuminate\Http\Request;

/**
 * Tickets en attente : le caissier met un ticket de côté pour servir le client suivant, puis le reprend.
 * Rien n'est vendu ni sorti du stock tant que le ticket n'est pas validé.
 */
class VenteEnAttenteController extends Controller
{
    public const MAXIMUM = 20;

    public function store(Request $request, VenteService $ventes)
    {
        $d = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'lignes.*.conditionnement' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:100'],
        ], ['lignes.required' => 'Le ticket est vide.']);
        if (VenteEnAttente::count() >= self::MAXIMUM) {
            return back()->withInput()->with('erreur', 'Trop de tickets en attente ('.self::MAXIMUM.') : validez ou supprimez-en avant d\'en ajouter.');
        }
        $client = ! empty($d['client_id']) ? Client::find($d['client_id']) : null;
        [, $total] = $ventes->detaillerLignes(collect($d['lignes']), $client, false);

        $t = VenteEnAttente::create([
            'user_id' => $request->user()->id, 'client_id' => $client?->id,
            'libelle' => $d['note'] ?? $client?->nomComplet(),
            'lignes' => array_values(array_map(fn ($l) => [
                'produit_id' => (int) $l['produit_id'], 'quantite' => (float) $l['quantite'], 'conditionnement' => ! empty($l['conditionnement']),
            ], $d['lignes'])),
            'total' => $total,
        ]);

        return redirect()->route('ventes.create')->with('succes', 'Ticket mis en attente'.($t->libelle ? " ({$t->libelle})" : '').' : '.gnf($total).'. Vous pouvez servir le client suivant.');
    }

    /** Recharge le ticket dans la caisse ; il est supprimé une fois la vente validée. */
    public function reprendre(VenteEnAttente $attente)
    {
        return redirect()->route('ventes.create')->withInput([
            'lignes' => $attente->lignes, 'client_id' => $attente->client_id, 'attente_id' => $attente->id,
        ]);
    }

    public function destroy(VenteEnAttente $attente)
    {
        $attente->delete();

        return back()->with('succes', 'Ticket en attente supprimé.');
    }
}
