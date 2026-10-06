<?php

namespace App\Http\Controllers;

use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\JournalActivite;
use App\Models\Produit;
use App\Models\User;
use App\Services\DevisService;
use App\Services\Promotions;
use App\Support\BoutiqueCourante;
use App\Support\Tva;
use Illuminate\Http\Request;

/**
 * Vitrine en ligne d'une boutique (page publique) : catalogue avec prix et disponibilité en temps réel,
 * commande envoyée par WhatsApp et enregistrée comme devis « Vitrine » à valider par le commerçant.
 * Aucun stock n'est réservé tant que le commerçant n'a pas transformé la commande en vente.
 */
class VitrineController extends Controller
{
    /** Boutique dont la vitrine est ouverte (sinon 404, sans rien révéler). */
    private function boutique(string $slug): Boutique
    {
        $b = Boutique::where('slug', $slug)->firstOrFail();
        abort_unless($b->vitrine_active && $b->estActive() && $b->aFonction('vitrine'), 404);
        app(BoutiqueCourante::class)->definir($b); // prix, promotions et stock de cette boutique

        return $b;
    }

    public function index(string $slug, Promotions $promotions)
    {
        $b = $this->boutique($slug);
        $produits = Produit::where('actif', true)->where('en_vitrine', true)->where('prix_vente', '>', 0)->with('categorie')->orderBy('designation')->get();

        return view('vitrine.index', [
            'b' => $b,
            'categories' => Categorie::whereIn('id', $produits->pluck('categorie_id')->filter())->orderBy('nom')->get(),
            'produits' => $produits->map(fn (Produit $p) => [
                'id' => $p->id, 'nom' => $p->designation, 'categorie' => $p->categorie_id, 'unite' => $p->unite,
                // Prix montrés au client : ceux qu'il paiera (TVA comprise si la boutique la facture)
                'prix' => Tva::prixClient($p->prix_vente, $p), 'promo' => Tva::prixClient($promotions->prix($p), $p), 'image' => $p->imageUrl(),
                'dispo' => $p->stock <= 0 ? 'rupture' : ($p->seuil_alerte > 0 && $p->stock <= $p->seuil_alerte ? 'limite' : 'ok'),
                'stock' => $b->vitrine_stock_visible ? (float) $p->stock : null,
            ])->values(),
        ]);
    }

    public function commander(Request $request, string $slug, DevisService $devis)
    {
        $b = $this->boutique($slug);
        \App\Support\AntiRobot::verifier($request, 'vitrine');
        $d = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'telephone' => ['required', 'string', 'max:30', 'regex:/^[0-9 +().-]{8,30}$/'],
            'note' => ['nullable', 'string', 'max:500'],
            'lignes' => ['required', 'array', 'min:1', 'max:50'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:1', 'max:10000'],
        ], ['telephone.regex' => 'Indiquez un numéro de téléphone valide.', 'lignes.required' => 'Votre panier est vide.']);

        // Seuls les produits réellement présentés en vitrine peuvent être commandés
        $autorises = Produit::where('actif', true)->where('en_vitrine', true)->whereIn('id', collect($d['lignes'])->pluck('produit_id'))->pluck('id')->all();
        $lignes = collect($d['lignes'])->filter(fn ($l) => in_array((int) $l['produit_id'], $autorises, true))
            ->map(fn ($l) => ['produit_id' => (int) $l['produit_id'], 'quantite' => (float) $l['quantite']])->values()->all();
        if (! $lignes) {
            return back()->withInput()->withErrors(['lignes' => "Ces produits ne sont plus proposés en ligne. Actualisez la page et refaites votre panier."]);
        }

        $commande = $devis->creer(['lignes' => $lignes, 'client_nom' => $d['nom'], 'client_telephone' => $d['telephone'],
            'note' => trim('Commande en ligne. '.($d['note'] ?? '')), 'origine' => 'vitrine'], false, null);
        JournalActivite::noterPour($b->id, 'vitrine', "Commande en ligne {$commande->numero} de {$d['nom']} ({$d['telephone']}) : ".gnf($commande->total_ttc));

        // Le commerçant est prévenu dans le logiciel (et par e-mail)
        $admins = User::where('boutique_id', $b->id)->where('actif', true)->get()->filter(fn (User $u) => $u->aPermission('ventes.creer'));
        $notification = new \App\Notifications\CommandeVitrine($commande);
        \Illuminate\Support\Facades\Notification::sendNow($admins, $notification, ['database']);
        \App\Support\Courrier::envoyer($admins->filter(fn ($u) => $u->role?->systeme), $notification, 'commande', $b->id);

        $message = "Bonjour {$b->nom}, je souhaite commander (réf. {$commande->numero}) :\n"
            .$commande->lignes->map(fn ($l) => '- '.qte($l->quantite).' × '.$l->designation.' = '.gnf($l->total))->implode("\n")
            ."\nTotal estimé : ".gnf($commande->total_ttc)."\nNom : {$d['nom']} — Tél. : {$d['telephone']}".($d['note'] ?? null ? "\nNote : {$d['note']}" : '');

        // Redirection (et non une page directe) : actualiser la page ne renvoie pas la commande une seconde fois
        return redirect()->route('vitrine.merci', $b->slug)->with('vitrine_commande', [
            'numero' => $commande->numero, 'total' => $commande->total_ttc, 'whatsapp' => lien_whatsapp($b->telephone, $message)]);
    }

    public function merci(string $slug)
    {
        $b = $this->boutique($slug);
        $commande = session('vitrine_commande');
        if (! $commande) {
            return redirect()->route('vitrine.index', $b->slug);
        }

        return view('vitrine.merci', ['b' => $b, 'commande' => $commande]);
    }
}
