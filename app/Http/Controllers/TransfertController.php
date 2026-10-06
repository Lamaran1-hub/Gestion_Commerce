<?php

namespace App\Http\Controllers;

use App\Models\Boutique;
use App\Models\Produit;
use App\Models\Transfert;
use App\Services\TransfertService;
use Illuminate\Http\Request;

/** Transferts de stock entre les boutiques du réseau. */
class TransfertController extends Controller
{
    /** Le transfert concerne-t-il la boutique où l'on travaille ? */
    private function verifierAcces(Transfert $t): void
    {
        abort_unless(in_array(boutique()->id, [$t->boutique_source_id, $t->boutique_destination_id], true), 404);
    }

    public function index()
    {
        $id = boutique()->id;

        return view('transferts.index', [
            'aRecevoir' => Transfert::with(['source', 'expediteur'])->where('boutique_destination_id', $id)->where('statut', 'envoye')->latest('envoye_le')->get(),
            'transferts' => Transfert::de($id)->with(['source', 'destination'])->latest('envoye_le')->paginate(25),
            'autres' => boutique()->reseau()->where('id', '!=', $id)->values(),
        ]);
    }

    public function create()
    {
        $autres = boutique()->reseau()->where('id', '!=', boutique()->id)->values();
        if ($autres->isEmpty()) {
            return redirect()->route('transferts.index')->with('erreur', 'Votre réseau ne compte qu\'une boutique : créez d\'abord un autre point de vente (menu Mes boutiques).');
        }

        return view('transferts.create', [
            'destinations' => $autres,
            'produits' => Produit::where('actif', true)->where('stock', '>', 0)->orderBy('designation')->get(['id', 'designation', 'code_barre', 'stock', 'unite', 'prix_achat']),
        ]);
    }

    public function store(Request $request, TransfertService $service)
    {
        $d = $request->validate([
            'boutique_destination_id' => ['required', 'integer'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer'],
            'lignes.*.quantite' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ], ['lignes.required' => 'Ajoutez au moins un produit à transférer.']);
        $destination = Boutique::findOrFail($d['boutique_destination_id']);
        $t = $service->envoyer($destination, $d['lignes'], $d['note'] ?? null, $request->user());

        return redirect()->route('transferts.show', $t)->with('succes', "Transfert {$t->numero} envoyé à « {$destination->nom} » : la marchandise est sortie de votre stock. "
            .'Elle entrera dans le stock de l\'autre boutique à sa réception.');
    }

    public function show(Transfert $transfert)
    {
        $this->verifierAcces($transfert);
        $transfert->load(['lignes', 'source', 'destination', 'expediteur', 'receptionnaire']);

        return view('transferts.show', ['t' => $transfert, 'estDestination' => $transfert->boutique_destination_id === boutique()->id]);
    }

    public function recevoir(Request $request, Transfert $transfert, TransfertService $service)
    {
        $this->verifierAcces($transfert);
        $d = $request->validate([
            'recues' => ['required', 'array'],
            'recues.*' => ['required', 'numeric', 'min:0'],
            'note_reception' => ['nullable', 'string', 'max:500'],
        ]);
        $t = $service->recevoir($transfert, $d['recues'], $d['note_reception'] ?? null, $request->user());
        $manque = $t->lignes->sum(fn ($l) => $l->ecart());

        return back()->with($manque > 0 ? 'erreur' : 'succes', "Transfert {$t->numero} réceptionné : la marchandise est entrée dans votre stock."
            .($manque > 0 ? ' Des manques ont été enregistrés en perte en transit.' : ''));
    }

    public function annuler(Request $request, Transfert $transfert, TransfertService $service)
    {
        $this->verifierAcces($transfert);
        $t = $service->annuler($transfert, $request->user());

        return back()->with('succes', "Transfert {$t->numero} annulé : la marchandise est revenue dans votre stock.");
    }
}
