<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Demande;
use Illuminate\Http\Request;

/** Demandes d'assistance envoyées par les boutiques. */
class DemandeController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.demandes.index', [
            'demandes' => Demande::with(['boutique', 'auteur'])
                ->when($request->statut, fn ($q) => $q->where('statut', $request->statut), fn ($q) => $q->where('statut', '!=', 'fermee'))
                ->orderBy('lue_proprietaire')->latest('dernier_message_le')->paginate(25)->withQueryString(),
        ]);
    }

    public function show(Demande $demande)
    {
        $demande->update(['lue_proprietaire' => true]);

        return view('admin.demandes.show', ['demande' => $demande->load(['boutique', 'auteur', 'messages.auteur'])]);
    }

    public function repondre(Request $request, Demande $demande)
    {
        $d = $request->validate(['contenu' => ['required', 'string', 'max:5000']]);
        $demande->repondre($request->user(), $d['contenu']);

        return back()->with('succes', 'Réponse envoyée : le client la verra dans son espace Assistance.');
    }

    public function cloturer(Demande $demande)
    {
        $demande->update(['statut' => 'fermee', 'lue_proprietaire' => true]);

        return redirect()->route('admin.demandes.index')->with('succes', 'Demande clôturée.');
    }
}
