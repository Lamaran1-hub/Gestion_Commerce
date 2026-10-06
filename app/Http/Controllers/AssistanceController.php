<?php

namespace App\Http\Controllers;

use App\Models\Demande;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Échanges d'une boutique avec le propriétaire du logiciel : assistance et licence. */
class AssistanceController extends Controller
{
    /** Page « Ma licence » : état, historique des paiements, comment renouveler. */
    public function licence(Request $request, \App\Services\Paiement\LicenceEnLigneService $enLigne)
    {
        $b = boutique();
        $request->user()->unreadNotifications()->where('type', \App\Notifications\LicenceActivee::class)->update(['read_at' => now()]);

        return view('abonnement', [
            'b' => $b->load('plan'),
            'paiements' => $b->paiementsLicence()->limit(12)->get(),
            'enLigne' => $enLigne->disponible() && $request->user()->aPermission('parametres.gerer'),
            'plans' => \App\Models\Plan::where('actif', true)->where('prix_mensuel', '>', 0)->orderBy('prix_mensuel')->get(),
            'commandes' => \App\Models\CommandeLicence::where('boutique_id', $b->id)->with('plan')->latest()->limit(8)->get(),
            'suivie' => $request->query('commande'),
        ]);
    }

    public function index()
    {
        return view('assistance.index', [
            'demandes' => Demande::where('boutique_id', boutique()->id)->with('auteur')->latest('dernier_message_le')->paginate(20),
        ]);
    }

    public function create(Request $request)
    {
        return view('assistance.create', ['categorie' => $request->get('categorie', 'question')]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'sujet' => ['required', 'string', 'max:150'],
            'categorie' => ['required', Rule::in(array_keys(Demande::CATEGORIES))],
            'contenu' => ['required', 'string', 'max:5000'],
        ]);
        $demande = Demande::create([
            'boutique_id' => boutique()->id, 'user_id' => $request->user()->id,
            'sujet' => $d['sujet'], 'categorie' => $d['categorie'],
        ]);
        $demande->repondre($request->user(), $d['contenu']);

        return redirect()->route('assistance.show', $demande)->with('succes', 'Votre demande est envoyée. Vous serez notifié dès que nous aurons répondu.');
    }

    public function show(Demande $demande)
    {
        $this->verifier($demande);
        $demande->update(['lue_boutique' => true]);

        return view('assistance.show', ['demande' => $demande->load(['messages.auteur', 'auteur'])]);
    }

    public function repondre(Request $request, Demande $demande)
    {
        $this->verifier($demande);
        $d = $request->validate(['contenu' => ['required', 'string', 'max:5000']]);
        $demande->repondre($request->user(), $d['contenu']);

        return back()->with('succes', 'Message envoyé.');
    }

    /** Une boutique ne voit que ses propres demandes. */
    private function verifier(Demande $demande): void
    {
        abort_unless($demande->boutique_id === boutique()->id, 404);
    }
}
