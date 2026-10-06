<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Paiement;
use App\Models\Retour;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Relevé de compte d'un client, comme un extrait bancaire :
 * achats au débit, versements et retours au crédit, solde progressif.
 * Les ventes annulées (et leurs paiements) sont exclues. Le solde final
 * est égal au « reste à payer » du client.
 */
class ReleveClient
{
    /** @return array{du:Carbon, au:Carbon, solde_initial:int, lignes:Collection, total_debit:int, total_credit:int, solde_final:int} */
    public function construire(Client $client, ?Carbon $du = null, ?Carbon $au = null): array
    {
        $au = ($au ?? now())->copy()->endOfDay();
        $ventes = Vente::where('client_id', $client->id)->where('statut', 'validee')->get(['id', 'numero', 'date_vente', 'total_ttc', 'montant_retourne']);
        $du = ($du ?? $ventes->min('date_vente') ?? now())->copy()->startOfDay();
        $ids = $ventes->pluck('id');

        // Achat = montant d'origine (avant retours) ; le retour vient ensuite au crédit
        $mouvements = $ventes->map(fn (Vente $v) => [
            'date' => $v->date_vente, 'libelle' => 'Achat '.$v->numero,
            'debit' => (int) $v->total_ttc + (int) $v->montant_retourne, 'credit' => 0,
        ])->concat(Paiement::whereIn('vente_id', $ids)->with('vente:id,numero')->get()->map(fn (Paiement $p) => [
            'date' => $p->date_paiement,
            'libelle' => ($p->montant < 0 ? 'Remboursement ' : 'Versement ').$p->libelleMode().' — '.$p->vente?->numero,
            'debit' => $p->montant < 0 ? -$p->montant : 0, 'credit' => max(0, (int) $p->montant),
        ]))->concat(Retour::whereIn('vente_id', $ids)->with('vente:id,numero')->get()->map(fn (Retour $r) => [
            'date' => $r->created_at, 'libelle' => 'Retour '.$r->numero.' — '.$r->vente?->numero,
            'debit' => 0, 'credit' => (int) $r->montant,
        ]))->sortBy(fn ($m) => [$m['date']->timestamp, $m['debit'] > 0 ? 0 : 1])->values();

        $avant = $mouvements->filter(fn ($m) => $m['date']->lt($du));
        $solde = (int) ($avant->sum('debit') - $avant->sum('credit'));
        $soldeInitial = $solde;
        $lignes = $mouvements->filter(fn ($m) => $m['date']->between($du, $au))->map(function ($m) use (&$solde) {
            $solde += $m['debit'] - $m['credit'];

            return $m + ['solde' => $solde];
        })->values();

        return [
            'du' => $du, 'au' => $au, 'solde_initial' => $soldeInitial, 'lignes' => $lignes,
            'total_debit' => (int) $lignes->sum('debit'), 'total_credit' => (int) $lignes->sum('credit'), 'solde_final' => $solde,
        ];
    }
}
