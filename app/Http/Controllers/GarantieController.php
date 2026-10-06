<?php

namespace App\Http\Controllers;

use App\Models\LigneVente;
use App\Models\NumeroSerie;
use App\Models\Vente;
use App\Services\NumerosSerie;
use Illuminate\Http\Request;

/** Garanties : retrouver un article vendu par son numéro de série (ou le téléphone du client) et savoir s'il est encore garanti. */
class GarantieController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->q);
        $resultats = collect();
        if (mb_strlen($q) >= 3) {
            $numero = NumeroSerie::normaliser($q);
            // Par numéro de série (partiel accepté : les 6 derniers chiffres d'un IMEI suffisent)
            $parSerie = NumeroSerie::with(['vente.client', 'ligne.vente', 'produit', 'retour:id,numero'])->where('numero', 'like', "%{$numero}%")->latest('id')->limit(30)->get()
                ->map(fn (NumeroSerie $n) => ['ligne' => $n->ligne, 'vente' => $n->vente, 'numero' => $n->numero, 'rapporte' => $n->retour?->numero]);
            // Par client (nom ou téléphone) : ses articles sous garantie
            $parClient = LigneVente::with(['vente.client', 'numerosSerie'])->whereNotNull('garantie_mois')
                ->whereHas('vente', fn ($v) => $v->whereHas('client', fn ($c) => $c->recherche($q)))->latest('id')->limit(30)->get()
                ->map(fn (LigneVente $l) => ['ligne' => $l, 'vente' => $l->vente, 'numero' => $l->numerosSerie->pluck('numero')->implode(', ') ?: null, 'rapporte' => null])
                ->filter(fn ($r) => $r['ligne']->quantite > 0);   // article entièrement rapporté : plus sous garantie chez ce client
            $resultats = $parSerie->concat($parClient)->unique(fn ($r) => $r['ligne']?->id.'|'.$r['numero'])->values();
        }

        return view('ventes.garanties', ['q' => $q, 'resultats' => $resultats]);
    }

    public function enregistrer(Request $request, Vente $vente, NumerosSerie $service)
    {
        $request->validate(['series' => ['required', 'array'], 'series.*' => ['array'], 'series.*.*' => ['nullable', 'string', 'max:60']]);
        $n = $service->enregistrer($vente, $request->series);

        return redirect()->to(route('ventes.show', $vente).'#series')->with('succes', "{$n} numéro(s) de série enregistré(s). Ils figurent sur le reçu et la facture.");
    }
}
