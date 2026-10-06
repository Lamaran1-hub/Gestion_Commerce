<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Vente;
use App\Services\VenteService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Suivi des crédits clients (ancienne fenêtre FI_Credit) : qui doit combien, et pour quand. */
class CreditController extends Controller
{
    public function index(Request $request)
    {
        $aujourdhui = now()->toDateString();
        $semaine = now()->addDays(7)->toDateString();
        $soldes = Vente::avecReste()->whereNotNull('client_id')
            ->select('client_id', DB::raw('SUM(total_ttc - montant_paye) as du'), DB::raw('COUNT(*) as nb'), DB::raw('MIN(date_vente) as plus_ancienne'),
                DB::raw('MIN(echeance) as prochaine_echeance'))
            ->selectRaw('SUM(CASE WHEN echeance < ? THEN total_ttc - montant_paye ELSE 0 END) as en_retard', [$aujourdhui])
            ->groupBy('client_id')->get()
            // Les retards d'abord (du plus ancien), puis par prochaine échéance
            ->sortBy(fn ($s) => [$s->en_retard > 0 ? 0 : 1, $s->prochaine_echeance ?? '9999-12-31'])->values();

        $clients = Client::whereIn('id', $soldes->pluck('client_id'))->recherche($request->q)->get()->keyBy('id');
        $soldes = $soldes->filter(fn ($s) => $clients->has($s->client_id));
        $vue = $request->vue;
        $compter = [
            'retard' => $soldes->filter(fn ($s) => $s->en_retard > 0)->count(),
            'semaine' => $soldes->filter(fn ($s) => $s->en_retard == 0 && $s->prochaine_echeance && $s->prochaine_echeance <= $semaine)->count(),
        ];
        $soldes = match ($vue) {
            'retard' => $soldes->filter(fn ($s) => $s->en_retard > 0),
            'semaine' => $soldes->filter(fn ($s) => $s->en_retard == 0 && $s->prochaine_echeance && $s->prochaine_echeance <= $semaine),
            default => $soldes,
        };

        return view('credits.index', [
            'soldes' => $soldes,
            'clients' => $clients,
            'total' => (int) $soldes->sum('du'),
            'totalRetard' => (int) $soldes->sum('en_retard'),
            'vue' => $vue,
            'compter' => $compter,
        ]);
    }

    public function store(Request $request, Client $client, VenteService $service)
    {
        $d = $request->validate([
            'montant' => ['required'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $montant = $service->encaisserClient($client, montant_saisi($d['montant']), $d['mode'], reference_paiement());

        return back()->with('succes', 'Versement de '.gnf($montant).' enregistré pour '.$client->nomComplet().'.');
    }

    /** Le client demande un délai : nouvelle date de paiement promise. */
    public function echeance(Request $request, Vente $vente, VenteService $service)
    {
        $d = $request->validate(['echeance' => ['required', 'date'], 'motif' => ['nullable', 'string', 'max:150']],
            ['echeance.required' => 'Choisissez la nouvelle date de paiement.']);
        $service->reporterEcheance($vente, $d['echeance'], $d['motif'] ?? null);

        return back()->with('succes', "{$vente->numero} : à payer avant le ".$vente->fresh()->echeance->format('d/m/Y').'.');
    }
}
