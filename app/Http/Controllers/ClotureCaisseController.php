<?php

namespace App\Http\Controllers;

use App\Models\ClotureCaisse;
use App\Models\User;
use App\Services\CaisseService;
use Illuminate\Http\Request;

/** Point de caisse du jour, clôture (rapport Z) et historique. */
class ClotureCaisseController extends Controller
{
    public function __construct(private CaisseService $caisse)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $voitTout = $user->aPermission('rapports.voir');
        $du = $request->du ?: now()->startOfMonth()->toDateString();
        $au = $request->au ?: now()->toDateString();

        $clotures = ClotureCaisse::with(['caissier', 'auteur'])
            ->whereDate('jour', '>=', $du)->whereDate('jour', '<=', $au)
            ->when(! $voitTout, fn ($q) => $q->where('user_id', $user->id))
            ->when($voitTout && $request->caissier, fn ($q) => $q->where('user_id', $request->caissier))
            ->latest('jour')->latest('id')->paginate(30)->withQueryString();

        return view('caisse.clotures', [
            'clotures' => $clotures,
            'voitTout' => $voitTout,
            'caissiers' => $voitTout ? User::where('boutique_id', boutique()->id)->orderBy('prenom')->get() : collect(),
            'ecartTotal' => (int) (clone $clotures->getCollection())->sum('ecart'),
            'ouverteAujourdhui' => ! $this->caisse->estCloturee($user),
            'du' => $du, 'au' => $au,
        ]);
    }

    public function create(Request $request)
    {
        $user = $request->user();
        if ($existante = ClotureCaisse::where('user_id', $user->id)->whereDate('jour', now()->toDateString())->first()) {
            return redirect()->route('clotures.show', $existante)->with('info', "Votre caisse est déjà clôturée aujourd'hui.");
        }

        return view('caisse.cloturer', ['bilan' => $this->caisse->bilan($user, now())]);
    }

    public function store(Request $request)
    {
        $request->merge(['especes_comptees' => montant_saisi($request->especes_comptees)]);
        $d = $request->validate([
            'especes_comptees' => ['required', 'integer', 'min:0'],
            'billetage' => ['nullable', 'array'],
            'billetage.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'motif' => ['nullable', 'string', 'max:150'],
            'motif_autre' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['especes_comptees.required' => 'Indiquez le montant des espèces comptées dans le tiroir.']);

        $cloture = $this->caisse->cloturer($request->user(), $d['especes_comptees'], choix_autre('motif'), $d['note'] ?? null, $request->user(), $d['billetage'] ?? null);

        return redirect()->route('clotures.show', $cloture)->with('succes', 'Caisse clôturée : '.$cloture->libelleEcart().'.');
    }

    public function show(Request $request, ClotureCaisse $cloture)
    {
        abort_unless($cloture->user_id === $request->user()->id || $request->user()->aPermission('rapports.voir'), 403);

        return view('caisse.rapport-z', ['c' => $cloture->load(['caissier', 'auteur'])]);
    }

    public function destroy(Request $request, ClotureCaisse $cloture)
    {
        $this->caisse->rouvrir($cloture, $request->user());

        return redirect()->route('clotures.index')->with('succes', "Caisse de {$cloture->caissier?->nomComplet()} rouverte : elle devra être clôturée à nouveau en fin de journée.");
    }
}
