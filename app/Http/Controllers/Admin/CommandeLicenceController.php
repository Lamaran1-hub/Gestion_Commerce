<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommandeLicence;
use App\Services\Paiement\LicenceEnLigneService;
use Illuminate\Http\Request;

/** Suivi des paiements de licence en ligne (Djomy) par le propriétaire. */
class CommandeLicenceController extends Controller
{
    public function __construct(private LicenceEnLigneService $service)
    {
    }

    public function index(Request $request)
    {
        // Consulter cette page vaut lecture des notifications de paiement
        $request->user()->unreadNotifications()->where('type', \App\Notifications\PaiementLicenceRecu::class)->update(['read_at' => now()]);

        return view('admin.commandes.index', [
            'commandes' => CommandeLicence::with(['boutique', 'plan', 'acheteur', 'paiementLicence'])
                ->when($request->statut, fn ($q) => $q->where('statut', $request->statut))
                ->latest()->paginate(30)->withQueryString(),
            'aTraiter' => CommandeLicence::whereIn('statut', ['a_valider', 'anomalie'])->count(),
            'disponible' => $this->service->disponible(),
        ]);
    }

    /** Teste la configuration et les identifiants Djomy (sans créer de paiement). */
    public function diagnostic(\App\Services\Paiement\DiagnosticDjomy $diagnostic)
    {
        return back()->with('diagnostic_djomy', $diagnostic->executer());
    }

    /** Relit le statut chez Djomy (utile si un webhook n'est pas arrivé). */
    public function verifier(CommandeLicence $commande)
    {
        $c = $this->service->synchroniser($commande);

        return back()->with('succes', "Commande {$c->reference} : {$c->libelleStatut()}.");
    }

    public function activer(Request $request, CommandeLicence $commande)
    {
        $c = $this->service->activer($commande, $request->user());

        return back()->with('succes', "Licence de {$c->boutique->nom} activée ({$c->paiementLicence?->numero}).");
    }
}
