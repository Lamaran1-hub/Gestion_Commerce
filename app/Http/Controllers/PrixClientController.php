<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\JournalActivite;
use App\Models\PrixClient;
use App\Models\Produit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Prix négociés avec un client. Accorder un prix, c'est accorder une remise durable :
 * réservé à ceux qui ont le droit de faire des remises, jamais sous le prix d'achat (sauf si la boutique l'autorise).
 */
class PrixClientController extends Controller
{
    public function store(Request $request, Client $client)
    {
        $request->merge(['prix' => montant_saisi($request->prix)]);
        $d = $request->validate([
            'produit_id' => ['required', Rule::exists('produits', 'id')->where('boutique_id', boutique()->id)->whereNull('deleted_at')],
            'prix' => ['required', 'integer', 'min:1'],
        ], ['produit_id.required' => 'Choisissez le produit.', 'prix.required' => 'Indiquez le prix convenu.']);
        $produit = Produit::findOrFail($d['produit_id']);

        if ($d['prix'] >= $produit->prix_vente) {
            throw ValidationException::withMessages(['prix' => "Le prix convenu doit être inférieur au prix normal de « {$produit->designation} » (".gnf($produit->prix_vente).').']);
        }
        if (! boutique()->vente_a_perte && $produit->prix_achat > 0 && $d['prix'] < $produit->prix_achat) {
            throw ValidationException::withMessages(['prix' => 'Ce prix est sous le prix d\'achat ('.gnf($produit->prix_achat).') : la vente à perte est désactivée dans les paramètres.']);
        }

        $existant = PrixClient::where('client_id', $client->id)->where('produit_id', $produit->id)->first();
        PrixClient::updateOrCreate(['client_id' => $client->id, 'produit_id' => $produit->id], ['prix' => $d['prix'], 'user_id' => $request->user()->id]);
        JournalActivite::noter('prix', "Prix convenu pour {$client->nomComplet()} : {$produit->designation} à ".gnf($d['prix'])
            .' (prix normal '.gnf($produit->prix_vente).')'.($existant ? ', au lieu de '.gnf($existant->prix) : ''));

        return redirect()->to(route('clients.show', $client).'#prixNegocies')
            ->with('succes', "{$produit->designation} : {$client->nomComplet()} paiera ".gnf($d['prix']).' au lieu de '.gnf($produit->prix_vente).'.');
    }

    public function destroy(Client $client, PrixClient $prix)
    {
        abort_unless($prix->client_id === $client->id, 404);
        $prix->delete();
        JournalActivite::noter('prix', "Prix convenu retiré pour {$client->nomComplet()} : ".($prix->produit?->designation ?? 'produit supprimé'));

        return redirect()->to(route('clients.show', $client).'#prixNegocies')->with('succes', 'Prix convenu retiré : le prix normal s\'appliquera.');
    }
}
