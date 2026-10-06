<?php

namespace App\Http\Controllers;

use App\Models\CarteCadeau;
use App\Models\Client;
use App\Models\MouvementCarteCadeau;
use App\Services\CartesCadeaux;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Cartes cadeaux : vendre une carte, la retrouver, l'imprimer, vérifier son solde à la caisse, l'annuler ou la prolonger. */
class CarteCadeauController extends Controller
{
    public function __construct(private CartesCadeaux $service)
    {
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->q);
        $etat = $request->etat;
        $cartes = CarteCadeau::with('auteur')
            ->when($q !== '', function ($r) use ($q) {
                $code = CartesCadeaux::normaliser($q);
                $r->where(fn ($w) => $w->where('code', 'like', '%'.$code.'%')->orWhere('beneficiaire', 'like', "%{$q}%")
                    ->orWhere('acheteur', 'like', "%{$q}%")->orWhere('telephone', 'like', "%{$q}%"));
            })
            ->when($etat === 'en_circulation', fn ($r) => $r->enCirculation())
            ->when($etat === 'annulees', fn ($r) => $r->where('statut', 'annulee'))
            ->latest('id')->paginate(25)->withQueryString();

        return view('cartes-cadeaux.index', [
            'cartes' => $cartes, 'q' => $q, 'etat' => $etat,
            'enCirculation' => (int) CarteCadeau::enCirculation()->sum('solde'),
            'nbEnCirculation' => CarteCadeau::enCirculation()->count(),
            'vendusMois' => (int) MouvementCarteCadeau::where('type', 'emission')->where('date_mouvement', '>=', now()->startOfMonth())->sum('montant'),
            'clients' => Client::orderBy('nom')->get(['id', 'nom', 'prenom', 'telephone']),
        ]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'montant' => ['required'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'client_id' => ['nullable', 'integer'],
            'acheteur' => ['nullable', 'string', 'max:120'],
            'beneficiaire' => ['nullable', 'string', 'max:120'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'message' => ['nullable', 'string', 'max:255'],
            'expire_le' => ['nullable', 'date'],
        ]);
        $d['montant'] = montant_saisi($d['montant']);
        $d['reference'] = reference_paiement();
        $carte = $this->service->emettre($d, $request->user());

        return redirect()->route('cartes-cadeaux.show', $carte)
            ->with('succes', 'Carte cadeau de '.gnf($carte->montant).' vendue. Remettez-la au client (imprimez-la ou envoyez-la par WhatsApp).')
            ->with('imprimer_carte', route('cartes-cadeaux.imprimer', $carte));
    }

    public function show(CarteCadeau $carte)
    {
        return view('cartes-cadeaux.show', ['c' => $carte->load(['auteur', 'client', 'mouvements.vente:id,numero', 'mouvements.auteur'])]);
    }

    /** Carte à remettre (ticket 80 mm) : code en grand, valeur, validité, message. */
    public function imprimer(CarteCadeau $carte)
    {
        abort_if($carte->statut === 'annulee', 404);
        $b = boutique();
        $message = 'Bonjour'.($carte->beneficiaire ? ' '.$carte->beneficiaire : '').",\n"
            .($carte->acheteur ? "{$carte->acheteur} vous offre" : 'Vous avez reçu').' une carte cadeau de '.gnf($carte->montant)." chez {$b->nom}.\n"
            .($carte->message ? "« {$carte->message} »\n" : '')
            .'Code : '.$carte->codeLisible()."\n"
            .($carte->expire_le ? "Valable jusqu'au ".$carte->expire_le->format('d/m/Y').".\n" : '')
            .'Présentez ce code à la caisse.'."\n{$b->nom}".($b->telephone ? " — {$b->telephone}" : '');

        return view('cartes-cadeaux.carte', ['boutique' => $b, 'c' => $carte, 'whatsapp' => lien_whatsapp($carte->telephone, $message)]);
    }

    /** Vérification depuis la caisse : solde et validité d'un code (réponse JSON). */
    public function verifier(Request $request)
    {
        $carte = $this->service->trouver($request->code);
        $refus = $this->service->refus($carte);

        return response()->json($refus ? ['ok' => false, 'message' => $refus] : [
            'ok' => true, 'code' => $carte->codeLisible(), 'solde' => $carte->solde,
            'message' => 'Solde disponible : '.gnf($carte->solde).($carte->expire_le ? ' — valable jusqu\'au '.$carte->expire_le->format('d/m/Y') : ''),
        ]);
    }

    public function annuler(Request $request, CarteCadeau $carte)
    {
        $d = $request->validate([
            'mode_remboursement' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'motif' => ['required', 'string', 'max:255'],
        ], ['motif.required' => 'Indiquez pourquoi la carte est annulée.']);
        $rendu = $this->service->annuler($carte, $d['mode_remboursement'], $d['motif'], $request->user());

        return back()->with('succes', 'Carte annulée.'.($rendu ? ' Rendez '.gnf($rendu).' au porteur ('.libelle_mode($d['mode_remboursement']).').' : ''));
    }

    public function prolonger(Request $request, CarteCadeau $carte)
    {
        $d = $request->validate(['expire_le' => ['required', 'date']]);
        $this->service->prolonger($carte, Carbon::parse($d['expire_le']));

        return back()->with('succes', 'Carte valable jusqu\'au '.$carte->fresh()->expire_le->format('d/m/Y').'.');
    }
}
