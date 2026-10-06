<?php

namespace App\Http\Controllers;

use App\Models\CommissionVersee;
use App\Models\User;
use App\Services\Commissions;
use App\Support\Tableau;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Commissions des vendeurs : relevé du mois, versements et historique (export pour la paie). */
class CommissionController extends Controller
{
    /** Mois demandé (AAAA-MM), par défaut le dernier mois terminé : c'est celui qu'on paie. */
    private function mois(Request $request): Carbon
    {
        if ($request->filled('mois') && preg_match('/^\d{4}-\d{2}$/', $request->mois)) {
            $m = Carbon::createFromFormat('Y-m-d', $request->mois.'-01')->startOfMonth();
            if ($m->lte(now()->startOfMonth()) && $m->gte(now()->subMonths(24)->startOfMonth())) {
                return $m;
            }
        }

        return now()->subMonthNoOverflow()->startOfMonth();
    }

    public function index(Request $request, Commissions $commissions)
    {
        $mois = $this->mois($request);
        $releve = $commissions->releve($mois);
        $choix = collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonthsNoOverflow($i));

        // Historique des 12 derniers mois : versé et reste à verser
        $versees = CommissionVersee::whereDate('mois', '>=', now()->subMonths(11)->startOfMonth()->toDateString())
            ->selectRaw('mois, SUM(montant) as total, COUNT(*) as nb')->groupBy('mois')->get()
            ->keyBy(fn ($r) => Carbon::parse($r->mois)->format('Y-m'));

        return view('equipe.commissions', [
            'mois' => $mois, 'releve' => $releve, 'choix' => $choix, 'versees' => $versees,
            'termine' => Commissions::estTermine($mois),
        ]);
    }

    public function verser(Request $request, Commissions $commissions)
    {
        $d = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->where('boutique_id', boutique()->id)],
            'mois' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'mode' => ['required', Rule::in(array_keys(config('gestion.modes_paiement')))],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);
        $vendeur = User::findOrFail($d['user_id']);
        $v = $commissions->verser($vendeur, Carbon::createFromFormat('Y-m-d', $d['mois'].'-01'), $d['mode'], $d['reference'] ?? null, $request->user());

        return back()->with('succes', "Commission de ".gnf($v->montant)." versée à {$vendeur->nomComplet()} (enregistrée en dépense « Salaires »).");
    }

    public function annuler(Request $request, CommissionVersee $versement, Commissions $commissions)
    {
        $commissions->annuler($versement, $request->user());

        return back()->with('succes', 'Versement annulé ; la dépense correspondante a été supprimée.');
    }

    public function export(Request $request, Commissions $commissions)
    {
        $mois = $this->mois($request);
        $lignes = $commissions->releve($mois)->map(fn ($l) => [
            'vendeur' => $l['user']->nomComplet(), 'ventes' => $l['nb_ventes'], 'ca_ht' => $l['ca_ht'],
            'taux' => rtrim(rtrim(number_format($l['taux'], 2, ',', ''), '0'), ',').' %', 'montant' => $l['montant'],
            'statut' => $l['versement'] ? 'Versée le '.$l['versement']->created_at->format('d/m/Y').' ('.libelle_mode($l['versement']->mode).')' : (Commissions::estTermine($mois) ? 'À verser' : 'Provisoire (mois en cours)'),
        ])->values()->all();

        return Tableau::telecharger($request->get('format') === 'pdf' ? 'pdf' : 'excel', 'Commissions '.$mois->translatedFormat('F Y'),
            ['vendeur' => 'Vendeur', 'ventes' => 'Ventes', 'ca_ht' => 'CA hors taxes', 'taux' => 'Taux', 'montant' => 'Commission', 'statut' => 'Statut'],
            $lignes, ['ca_ht', 'montant'], 'Commissions calculées sur le chiffre d\'affaires hors taxes du mois',
            ['ca_ht' => array_sum(array_column($lignes, 'ca_ht')), 'montant' => array_sum(array_column($lignes, 'montant'))]);
    }
}
