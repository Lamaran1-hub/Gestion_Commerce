<?php

namespace App\Http\Controllers;

use App\Models\Acompte;
use App\Models\Client;
use App\Models\Devis;
use App\Services\Acomptes;
use App\Services\DevisService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Devis / factures proforma. */
class DevisController extends Controller
{
    public function __construct(private DevisService $service)
    {
    }

    public function index(Request $request)
    {
        $devis = Devis::with(['client', 'auteur'])
            ->when($request->etat === 'en_cours', fn ($q) => $q->where('statut', 'en_cours')->whereDate('valable_jusqu_au', '>=', now()->toDateString()))
            ->when($request->etat === 'expire', fn ($q) => $q->where('statut', 'en_cours')->whereDate('valable_jusqu_au', '<', now()->toDateString()))
            ->when(in_array($request->etat, ['converti', 'annule'], true), fn ($q) => $q->where('statut', $request->etat))
            ->when($request->etat === 'en_ligne', fn ($q) => $q->commandesATraiter())
            ->latest('id')->paginate(25)->withQueryString();

        return view('devis.index', ['devis' => $devis]);
    }

    /** Enregistré depuis la caisse (même ticket, bouton « Enregistrer comme devis »). */
    public function store(Request $request)
    {
        $d = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'client_nom' => ['nullable', 'string', 'max:120'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'lignes.*.prix_unitaire' => ['nullable'],
            'lignes.*.conditionnement' => ['nullable', 'boolean'],
            'remise' => ['nullable'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['lignes.required' => 'Ajoutez au moins un produit au devis.']);
        $peutRemise = $request->user()->aPermission('ventes.remise');
        $d['remise'] = $peutRemise ? montant_saisi($d['remise'] ?? 0) : 0;

        $devis = $this->service->creer($d, $peutRemise, $request->user());

        return redirect()->route('devis.show', $devis)->with('succes', "Devis {$devis->numero} enregistré, valable jusqu'au "
            .$devis->valable_jusqu_au->format('d/m/Y').'. Aucun produit n\'est sorti du stock.');
    }

    public function show(Devis $devi)
    {
        return view('devis.show', ['d' => $devi->load(['lignes', 'client', 'auteur', 'vente', 'acomptes.auteur']),
            'clients' => $devi->client_id || $devi->client_nom ? collect() : Client::orderBy('nom')->get(['id', 'nom', 'prenom', 'telephone'])]);
    }

    public function pdf(Devis $devi)
    {
        return Pdf::loadView('pdf.proforma', ['d' => $devi->load(['lignes', 'client']), 'boutique' => boutique()])
            ->setPaper('a4')->stream("proforma-{$devi->numero}.pdf");
    }

    public function convertir(Request $request, Devis $devi)
    {
        $d = $request->validate([
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'montant_recu' => ['nullable'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $d['montant_recu'] = isset($d['montant_recu']) && $d['montant_recu'] !== '' ? montant_saisi($d['montant_recu']) : null;
        $vente = $this->service->convertir($devi, $d, $request->user());

        return redirect()->route('ventes.show', ['vente' => $vente, 'imprimer' => 1])
            ->with('succes', "Devis {$devi->numero} transformé en vente {$vente->numero}.");
    }

    public function annuler(Request $request, Devis $devi)
    {
        $d = $request->validate(['mode_remboursement' => ['nullable', Rule::in(array_keys(config('gestion.modes_paiement')))]]);
        $rendu = $this->service->annuler($devi, $d['mode_remboursement'] ?? 'especes', $request->user());

        return back()->with('succes', "Devis {$devi->numero} annulé."
            .($rendu ? ' Rendez '.gnf($rendu)." d'acompte au client (".libelle_mode($d['mode_remboursement'] ?? 'especes').').' : ''));
    }

    /** Indiquer le client d'un devis fait au comptoir (nécessaire pour un acompte, un rappel, une livraison). */
    public function client(Request $request, Devis $devi)
    {
        $d = $request->validate([
            'client_id' => ['nullable', 'integer'],
            'client_nom' => ['required_without:client_id', 'nullable', 'string', 'max:120'],
            'client_telephone' => ['nullable', 'string', 'max:30'],
        ], ['client_nom.required_without' => 'Choisissez un client ou saisissez son nom.']);
        abort_unless($devi->statut === 'en_cours', 403);
        $client = ! empty($d['client_id']) ? Client::findOrFail($d['client_id']) : null;
        $devi->update($client ? ['client_id' => $client->id, 'client_nom' => null]
            : ['client_nom' => trim($d['client_nom']), 'client_telephone' => $d['client_telephone'] ?? $devi->client_telephone]);

        return back()->with('succes', 'Client du devis : '.$devi->fresh('client')->nomClient().'.');
    }

    /** Le client verse une avance sur sa commande. */
    public function acompte(Request $request, Devis $devi, Acomptes $acomptes)
    {
        $request->merge(['montant' => montant_saisi($request->montant)]);
        $d = $request->validate([
            'montant' => ['required', 'integer', 'min:1'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference' => ['nullable', 'string', 'max:120'],
        ], ['montant.required' => "Indiquez le montant de l'acompte."]);
        $a = $acomptes->verser($devi, $d['montant'], $d['mode'], $d['reference'] ?? null, $request->user());
        session()->flash('recu_acompte', route('acomptes.recu', $a));

        return back()->with('succes', 'Acompte de '.gnf($a->montant).' encaissé. Reste à payer à la livraison : '.gnf($devi->fresh()->resteAPayer()).'.');
    }

    /** Reçu d'acompte (ticket 80 mm), à remettre au client. */
    public function recuAcompte(Acompte $acompte)
    {
        $d = $acompte->devis()->with(['lignes', 'client'])->firstOrFail();
        $b = boutique();
        $tel = $d->client?->telephone ?? $d->client_telephone;
        $message = 'Bonjour '.$d->nomClient().",\n{$b->nom} a bien reçu votre acompte de ".gnf($acompte->montant)
            ." pour la commande {$d->numero} (".gnf($d->total_ttc).").\nTotal déjà versé : ".gnf($d->acompte).'. Reste à payer : '.gnf($d->resteAPayer())
            .".\n{$b->nom}".($b->telephone ? " — {$b->telephone}" : '');

        return view('devis.recu-acompte', ['boutique' => $b, 'a' => $acompte->load('auteur'), 'd' => $d, 'whatsapp' => lien_whatsapp($tel, $message)]);
    }

    public function renouveler(Request $request, Devis $devi)
    {
        $nouveau = $this->service->renouveler($devi, $request->user());

        return redirect()->route('devis.show', $nouveau)->with('succes', "Nouveau devis {$nouveau->numero} créé au prix du jour.");
    }
}
