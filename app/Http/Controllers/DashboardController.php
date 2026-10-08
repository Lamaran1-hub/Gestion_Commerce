<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Depense;
use App\Models\LigneVente;
use App\Models\Paiement;
use App\Models\Produit;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public const PERIODES = ['jour' => "Aujourd'hui", 'semaine' => '7 derniers jours', 'mois' => 'Ce mois', 'annee' => 'Cette année'];

    public function __invoke(Request $request)
    {
        if (! $request->user()->aPermission('dashboard.voir')) {
            return redirect()->route($request->user()->aPermission('ventes.creer') ? 'ventes.create' : 'produits.index');
        }

        $periode = array_key_exists($request->periode, self::PERIODES) ? $request->periode : 'mois';
        [$du, $au] = self::bornes($periode);

        $ventes = Vente::validees()->whereBetween('date_vente', [$du, $au]);
        $ca = (int) (clone $ventes)->sum('total_ttc');
        $nbVentes = (clone $ventes)->count();

        $marge = (int) LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')
            ->whereBetween('ventes.date_vente', [$du, $au])
            ->sum(DB::raw('lignes_vente.prix_achat * lignes_vente.quantite')) * -1
            // Marge = chiffre d'affaires hors taxe (remise déduite) − coût d'achat : juste en prix HT comme en prix TTC
            + (int) (clone $ventes)->sum(DB::raw('total_ttc - total_tva'));

        $depenses = (int) Depense::whereDate('date_depense', '>=', $du->toDateString())->whereDate('date_depense', '<=', $au->toDateString())->sum('montant');

        $encaisse = (int) Paiement::whereBetween('date_paiement', [$du, $au])
            ->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))->sum('montant');

        $credits = (int) Vente::avecReste()->sum(DB::raw('total_ttc - montant_paye'));

        $indicateurs = [
            'ca' => $ca,
            'nb_ventes' => $nbVentes,
            'panier_moyen' => $nbVentes ? intdiv($ca, $nbVentes) : 0,
            'marge' => $marge,
            'depenses' => $depenses,
            'benefice' => $marge - $depenses,
            'encaisse' => $encaisse,
            'credits' => $credits,
            'nb_clients' => Client::count(),
            'valeur_stock' => (int) Produit::stockables()->where('stock', '>', 0)->sum(DB::raw('stock * prix_achat')),
        ];

        $objectifs = app(\App\Services\Objectifs::class);
        $voirEquipe = fonction('equipe') && $request->user()->aPermission('utilisateurs.gerer');

        return view('dashboard', [
            'objectif' => $objectifs->boutique(),
            'equipe' => $voirEquipe ? $objectifs->equipe() : collect(),
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'i' => $indicateurs,
            'graphique' => $this->ventesParMois(),
            'topProduits' => $this->topProduits($du, $au),
            'alertes' => Produit::where('actif', true)->enAlerte()->orderBy('stock')->limit(8)->get(),
            'nbAlertes' => Produit::where('actif', true)->enAlerte()->count(),
            'dernieresVentes' => Vente::with('client')->latest('date_vente')->limit(6)->get(),
            'parMode' => Paiement::whereBetween('date_paiement', [$du, $au])
                ->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))
                ->select('mode', DB::raw('SUM(montant) as total'))->groupBy('mode')->pluck('total', 'mode'),
        ]);
    }

    /** @return array{0:Carbon,1:Carbon} */
    public static function bornes(string $periode): array
    {
        return match ($periode) {
            'jour' => [now()->startOfDay(), now()->endOfDay()],
            'semaine' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'annee' => [now()->startOfYear(), now()->endOfDay()],
            default => [now()->startOfMonth(), now()->endOfDay()],
        };
    }

    /** Chiffre d'affaires des 12 derniers mois (ancien FI_GraphMontantVentesPaMois). */
    private function ventesParMois(): array
    {
        $debut = now()->startOfMonth()->subMonths(11);
        $ventes = Vente::validees()->where('date_vente', '>=', $debut)->get(['date_vente', 'total_ttc'])
            ->groupBy(fn ($v) => $v->date_vente->format('Y-m'))
            ->map(fn ($g) => $g->sum('total_ttc'));

        $mois = [];
        for ($d = $debut->copy(); $d <= now(); $d->addMonth()) {
            $mois[] = ['label' => ucfirst($d->locale('fr')->isoFormat('MMM YY')), 'total' => (int) ($ventes[$d->format('Y-m')] ?? 0)];
        }

        return $mois;
    }

    private function topProduits(Carbon $du, Carbon $au)
    {
        return LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')
            ->whereBetween('ventes.date_vente', [$du, $au])
            ->select('lignes_vente.designation', DB::raw('SUM(lignes_vente.quantite) as quantite'), DB::raw('SUM(lignes_vente.total) as total'))
            ->groupBy('lignes_vente.designation')->orderByDesc('total')->limit(6)->get();
    }
}
