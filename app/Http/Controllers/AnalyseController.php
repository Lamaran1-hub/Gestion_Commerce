<?php

namespace App\Http\Controllers;

use App\Services\AnalyseVentes;
use Illuminate\Http\Request;

/** Analyse des ventes : indicateurs comparés, heures de pointe, rentabilité des produits. */
class AnalyseController extends Controller
{
    public function index(Request $request, AnalyseVentes $analyse)
    {
        $du = ($request->date('du') ?? now()->startOfMonth())->copy()->startOfDay();
        $au = ($request->date('au') ?? now())->copy()->endOfDay();
        if ($au->lt($du)) {
            [$du, $au] = [$au->copy()->startOfDay(), $du->copy()->endOfDay()];
        }
        // Période précédente de même durée, et même période l'année dernière
        $jours = (int) $du->diffInDays($au) + 1;
        $precedente = [$du->copy()->subDays($jours), $du->copy()->subSecond()];
        $anneeDerniere = [$du->copy()->subYear(), $au->copy()->subYear()];
        $avancee = fonction('rapports_avances');

        return view('rapports.analyse', [
            'du' => $du, 'au' => $au, 'jours' => $jours,
            'actuel' => $analyse->indicateurs($du, $au),
            'precedent' => $analyse->indicateurs(...$precedente),
            'n1' => $analyse->indicateurs(...$anneeDerniere),
            'periodePrecedente' => $precedente,
            'moyens' => $analyse->moyens($du, $au),
            'avancee' => $avancee,
            'repartition' => $avancee ? $analyse->repartition($du, $au) : null,
            'produits' => $avancee ? $analyse->produits($du, $au) : null,
        ]);
    }
}
