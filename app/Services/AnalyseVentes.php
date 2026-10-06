<?php

namespace App\Services;

use App\Models\LigneVente;
use App\Models\Paiement;
use App\Models\Vente;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Analyse des ventes d'une période : indicateurs clés comparés à la période précédente et à l'année
 * précédente, heures de pointe, jours de la semaine, rentabilité des produits, moyens de paiement.
 */
class AnalyseVentes
{
    /** Indicateurs clés d'une période. */
    public function indicateurs(Carbon $du, Carbon $au): array
    {
        $ventes = Vente::validees()->whereBetween('date_vente', [$du, $au]);
        $nb = (clone $ventes)->count();
        $ca = (int) (clone $ventes)->sum('total_ttc');
        $tva = (int) (clone $ventes)->sum('total_tva');
        $lignes = LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')->whereBetween('ventes.date_vente', [$du, $au]);
        $cout = (int) (clone $lignes)->sum(DB::raw('lignes_vente.prix_achat * lignes_vente.quantite'));
        $articles = (float) (clone $lignes)->sum(DB::raw('lignes_vente.quantite'));
        $marge = $ca - $tva - $cout;

        return [
            'ca' => $ca, 'nb' => $nb, 'panier' => $nb ? (int) round($ca / $nb) : 0,
            'articles' => $nb ? round($articles / $nb, 1) : 0, 'marge' => $marge,
            'taux_marge' => $ca - $tva > 0 ? round($marge * 100 / ($ca - $tva), 1) : 0,
            'clients' => (clone $ventes)->whereNotNull('client_id')->distinct('client_id')->count('client_id'),
        ];
    }

    /** Variation en % (null si pas de référence). */
    public static function variation(float|int $actuel, float|int $reference): ?float
    {
        return $reference ? round(($actuel - $reference) * 100 / abs($reference), 1) : null;
    }

    /** Chiffre d'affaires et nombre de ventes par heure (0-23) et par jour de semaine (1 = lundi). */
    public function repartition(Carbon $du, Carbon $au): array
    {
        $heures = array_fill(0, 24, ['ca' => 0, 'nb' => 0]);
        $jours = array_fill(1, 7, ['ca' => 0, 'nb' => 0]);
        foreach (Vente::validees()->whereBetween('date_vente', [$du, $au])->select(['date_vente', 'total_ttc'])->cursor() as $v) {
            $h = (int) $v->date_vente->format('G');
            $j = (int) $v->date_vente->format('N');
            $heures[$h]['ca'] += $v->total_ttc;
            $heures[$h]['nb']++;
            $jours[$j]['ca'] += $v->total_ttc;
            $jours[$j]['nb']++;
        }

        return ['heures' => $heures, 'jours' => $jours];
    }

    /** Produits : les plus vendus, les plus rentables, et ceux qui se vendent beaucoup en rapportant peu. */
    public function produits(Carbon $du, Carbon $au, int $limite = 10): array
    {
        $parProduit = LigneVente::join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', boutique()->id)->where('ventes.statut', 'validee')->whereBetween('ventes.date_vente', [$du, $au])
            ->whereNotNull('lignes_vente.produit_id')
            ->groupBy('lignes_vente.produit_id')
            ->select('lignes_vente.produit_id', DB::raw('MAX(lignes_vente.designation) as designation'),
                DB::raw('SUM(lignes_vente.quantite * lignes_vente.facteur) as quantite'), DB::raw('SUM('.\App\Support\Tva::sqlHt('lignes_vente.total').') as ca'),
                DB::raw('SUM('.\App\Support\Tva::sqlHt('lignes_vente.total').' - lignes_vente.prix_achat * lignes_vente.quantite) as marge'))
            ->get()->map(fn ($p) => ['designation' => $p->designation, 'quantite' => (float) $p->quantite, 'ca' => (int) $p->ca, 'marge' => (int) $p->marge,
                'taux' => $p->ca > 0 ? round($p->marge * 100 / $p->ca, 1) : 0]);
        $tauxMoyen = $parProduit->sum('ca') > 0 ? $parProduit->sum('marge') * 100 / $parProduit->sum('ca') : 0;

        return [
            'plus_vendus' => $parProduit->sortByDesc('ca')->take($limite)->values(),
            'plus_rentables' => $parProduit->sortByDesc('marge')->take($limite)->values(),
            // Beaucoup de ventes, peu de marge : prix ou coût d'achat à revoir
            'a_surveiller' => $parProduit->sortByDesc('ca')->take(30)->filter(fn ($p) => $p['taux'] < $tauxMoyen / 2)->take($limite)->values(),
            'taux_moyen' => round($tauxMoyen, 1),
        ];
    }

    /** Encaissements par moyen de paiement sur la période. */
    public function moyens(Carbon $du, Carbon $au): array
    {
        return Paiement::whereBetween('date_paiement', [$du, $au])->whereHas('vente', fn ($q) => $q->where('statut', 'validee'))
            ->selectRaw('mode, SUM(montant) as total')->groupBy('mode')->orderByDesc('total')->pluck('total', 'mode')
            ->map(fn ($t) => (int) $t)->all();
    }
}
