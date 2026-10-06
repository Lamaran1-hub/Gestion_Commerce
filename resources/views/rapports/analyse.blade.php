@extends('layouts.app')
@section('titre', 'Analyse des ventes')
@section('contenu')
    @php
        $variation = fn ($a, $b) => \App\Services\AnalyseVentes::variation($a, $b);
        $badge = function ($v) {
            if ($v === null) return '<span class="small text-doux">—</span>';
            $classe = $v > 0 ? 'text-success' : ($v < 0 ? 'text-danger' : 'text-doux');
            $fleche = $v > 0 ? 'arrow-up-right' : ($v < 0 ? 'arrow-down-right' : 'dash');
            return '<span class="small fw-semibold '.$classe.'"><i class="bi bi-'.$fleche.'"></i> '.($v > 0 ? '+' : '').number_format($v, 1, ',', ' ').' %</span>';
        };
        $kpis = [
            ['ca', "Chiffre d'affaires", true], ['nb', 'Nombre de ventes', false], ['panier', 'Panier moyen', true],
            ['articles', 'Articles par vente', false], ['marge', 'Marge brute', true], ['clients', 'Clients identifiés', false],
        ];
        // Marges visibles seulement avec le droit « prix d'achat et marges » (on en déduirait les prix d'achat)
        $voirMarges = auth()->user()->aPermission('produits.prix_achat');
        if (! $voirMarges) {
            $kpis = array_values(array_filter($kpis, fn ($k) => $k[0] !== 'marge'));
        }
        $joursSemaine = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
    @endphp
    <div class="entete-page">
        <div><h1>Analyse des ventes</h1>
            <div class="text-doux">Du {{ $du->format('d/m/Y') }} au {{ $au->format('d/m/Y') }} ({{ $jours }} jour(s)), comparé à la période précédente et à l'an dernier.</div></div>
        <a href="{{ route('rapports.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Rapports</a>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        @include('partials.periode', ['du' => $du->toDateString(), 'au' => $au->toDateString()])
        <div class="col-md-auto d-flex gap-1 flex-wrap">
            @foreach (["Aujourd'hui" => [now(), now()], '7 jours' => [now()->subDays(6), now()], 'Ce mois' => [now()->startOfMonth(), now()], 'Mois dernier' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()], 'Cette année' => [now()->startOfYear(), now()]] as $lib => [$a, $b])
                <a href="{{ route('rapports.analyse', ['du' => $a->toDateString(), 'au' => $b->toDateString()]) }}" class="btn btn-sm btn-light">{{ $lib }}</a>
            @endforeach
            <button class="btn btn-sm btn-primary">Appliquer</button>
        </div>
    </form>

    <div class="row g-3 mb-3">
        @foreach ($kpis as [$cle, $libelle, $argent])
            <div class="col-6 col-lg-4 col-xl-2">
                <div class="bloc kpi h-100">
                    <div class="etiquette">{{ $libelle }}</div>
                    <div class="valeur {{ $argent ? 'montant' : '' }}">{{ $argent ? gnf($actuel[$cle]) : (is_float($actuel[$cle]) ? number_format($actuel[$cle], 1, ',', ' ') : $actuel[$cle]) }}</div>
                    <div class="d-flex flex-column">
                        <span>{!! $badge($variation($actuel[$cle], $precedent[$cle])) !!} <span class="small text-doux">vs période préc.</span></span>
                        <span>{!! $badge($variation($actuel[$cle], $n1[$cle])) !!} <span class="small text-doux">vs an dernier</span></span>
                    </div>
                    @if ($cle === 'marge')<div class="small text-doux">Taux : {{ number_format($actuel['taux_marge'], 1, ',', ' ') }} %</div>@endif
                </div>
            </div>
        @endforeach
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="bloc h-100">
                <div class="bloc-entete"><h2 class="mb-0">Moyens de paiement</h2></div>
                <div class="bloc-corps">
                    @forelse ($moyens as $mode => $total)
                        <div class="d-flex justify-content-between small"><span>{{ libelle_mode($mode) }}</span><span class="montant">{{ gnf($total) }}</span></div>
                        <div class="progress mb-2" style="height:6px" role="presentation"><div class="progress-bar" style="width: {{ max(1, round($total * 100 / max(1, array_sum($moyens)))) }}%"></div></div>
                    @empty
                        <p class="vide mb-0">Aucun encaissement sur la période.</p>
                    @endforelse
                </div>
            </div>
        </div>
        @if ($avancee)
            <div class="col-lg-8">
                <div class="bloc h-100">
                    <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-clock-history me-1"></i>Heures de pointe</h2>
                        <span class="small text-doux">Prévoyez plus de vendeurs aux heures chargées</span></div>
                    <div class="bloc-corps">
                        @php
                            $maxHeure = max(1, max(array_column($repartition['heures'], 'ca')));
                            $heuresOuvertes = array_filter($repartition['heures'], fn ($h) => $h['nb'] > 0);
                        @endphp
                        @if ($heuresOuvertes)
                            <div class="d-flex align-items-end gap-1" style="height:160px" role="img" aria-label="Chiffre d'affaires par heure">
                                @foreach ($repartition['heures'] as $h => $v)
                                    @if ($h >= min(array_keys($heuresOuvertes)) && $h <= max(array_keys($heuresOuvertes)))
                                        <div class="flex-fill d-flex flex-column align-items-center justify-content-end h-100" title="{{ $h }} h : {{ gnf($v['ca']) }} ({{ $v['nb'] }} vente(s))">
                                            <div class="w-100 rounded-top {{ $v['ca'] == $maxHeure ? 'bg-warning' : 'bg-success' }}" style="height: {{ max(2, round($v['ca'] * 100 / $maxHeure)) }}%"></div>
                                            <span class="small text-doux">{{ $h }}h</span>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @else
                            <p class="vide mb-0">Pas encore de ventes sur la période.</p>
                        @endif
                    </div>
                </div>
            </div>
        @endif
    </div>

    @if ($avancee)
        <div class="row g-3 mb-3">
            <div class="col-lg-4">
                <div class="bloc h-100">
                    <div class="bloc-entete"><h2 class="mb-0">Jours de la semaine</h2></div>
                    <div class="bloc-corps">
                        @php($maxJour = max(1, max(array_column($repartition['jours'], 'ca'))))
                        @foreach ($repartition['jours'] as $j => $v)
                            <div class="d-flex justify-content-between small"><span>{{ $joursSemaine[$j] }}</span><span class="montant">{{ gnf($v['ca']) }}</span></div>
                            <div class="progress mb-2" style="height:6px" role="presentation"><div class="progress-bar {{ $v['ca'] == $maxJour ? 'bg-warning' : '' }}" style="width: {{ round($v['ca'] * 100 / $maxJour) }}%"></div></div>
                        @endforeach
                    </div>
                </div>
            </div>
            @foreach (array_filter(['plus_vendus' => ['Les plus vendus', 'ca'], 'plus_rentables' => ['Les plus rentables', 'marge']], fn ($v, $k) => $voirMarges || $k !== 'plus_rentables', ARRAY_FILTER_USE_BOTH) as $cle => [$titre, $tri])
                <div class="col-lg-4">
                    <div class="bloc h-100">
                        <div class="bloc-entete"><h2 class="mb-0">{{ $titre }}</h2></div>
                        <table class="table table-sm mb-0"><tbody>
                        @forelse ($produits[$cle] as $p)
                            <tr><td class="small">{{ $p['designation'] }}<div class="text-doux">{{ qte($p['quantite']) }} vendu(s)@if ($voirMarges) · marge {{ number_format($p['taux'], 1, ',', ' ') }} %@endif</div></td>
                                <td class="text-end montant small">{{ gnf($p[$tri]) }}</td></tr>
                        @empty
                            <tr><td class="vide">Aucune vente.</td></tr>
                        @endforelse
                        </tbody></table>
                    </div>
                </div>
            @endforeach
        </div>
        @if ($voirMarges && $produits['a_surveiller']->isNotEmpty())
            <div class="bloc mb-3 border-warning">
                <div class="bloc-entete"><h2 class="mb-0 text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i>Beaucoup vendus, peu rentables</h2>
                    <span class="small text-doux">Marge inférieure à la moitié de la moyenne ({{ number_format($produits['taux_moyen'], 1, ',', ' ') }} %) : revoyez le prix ou le fournisseur.</span></div>
                <table class="table table-sm mb-0"><tbody>
                    @foreach ($produits['a_surveiller'] as $p)
                        <tr><td>{{ $p['designation'] }}</td><td class="text-end montant">{{ gnf($p['ca']) }}</td>
                            <td class="text-end small text-danger">marge {{ number_format($p['taux'], 1, ',', ' ') }} %</td></tr>
                    @endforeach
                </tbody></table>
            </div>
        @endif
    @else
        <div class="bloc bloc-corps text-center">
            <i class="bi bi-lock fs-3 text-doux"></i>
            <p class="mb-2">Heures de pointe, jours forts et rentabilité des produits sont inclus dans les formules avec <strong>rapports avancés</strong>.</p>
            @can('parametres.gerer')<a href="{{ route('abonnement') }}" class="btn btn-sm btn-primary">Voir les formules</a>@endcan
        </div>
    @endif
@endsection
