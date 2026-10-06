@extends('layouts.app')
@section('titre', 'Rapports')
@section('contenu')
    <div class="entete-page"><div><h1>Rapports</h1><div class="text-doux">Choisissez la période, puis téléchargez le rapport en Excel ou en PDF.</div></div>
        <div class="d-flex gap-2">
            <a href="{{ route('rapports.analyse') }}" class="btn btn-primary"><i class="bi bi-graph-up-arrow me-1"></i>Analyse des ventes</a>
            <a href="{{ route('integrite.index') }}" class="btn btn-outline-primary"><i class="bi bi-shield-check me-1"></i>Intégrité des encaissements</a>
        </div></div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3" id="periode">
        @include('partials.periode')
        <div class="col-md-auto d-flex gap-1 flex-wrap">
            @foreach (['Ce mois' => [now()->startOfMonth(), now()], 'Mois dernier' => [now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth()], 'Cette année' => [now()->startOfYear(), now()]] as $lib => [$a, $b])
                <a href="{{ route('rapports.index', ['du' => $a->toDateString(), 'au' => $b->toDateString()]) }}" class="btn btn-sm btn-light">{{ $lib }}</a>
            @endforeach
            <button class="btn btn-sm btn-primary">Appliquer</button>
        </div>
    </form>
    <div class="bloc">
        @foreach ($rapports as $cle => [$titre, $description])
            @continue(in_array($cle, \App\Http\Controllers\RapportController::REPORTS_COUTS, true) && ! auth()->user()->aPermission('produits.prix_achat'))
            <div class="d-flex flex-wrap align-items-center gap-3 p-3 {{ $loop->last ? '' : 'border-bottom' }}">
                @php($verrou = in_array($cle, \App\Http\Controllers\RapportController::AVANCES, true) && ! fonction('rapports_avances'))
                <div class="flex-grow-1"><div class="fw-semibold">{{ $titre }}@if ($verrou) <span class="etat etat-neutre ms-1"><i class="bi bi-lock"></i> formule supérieure</span>@endif</div><div class="small text-doux">{{ $description }}</div></div>
                @if ($verrou)
                    <a href="{{ route('abonnement') }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-stars me-1"></i>Voir les formules</a>
                @else
                <a href="{{ route('rapports.export', [$cle, 'ecran', 'du' => $du, 'au' => $au]) }}" class="btn btn-sm btn-primary"><i class="bi bi-eye me-1"></i>Afficher</a>
                <a href="{{ route('rapports.export', [$cle, 'excel', 'du' => $du, 'au' => $au]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
                <a href="{{ route('rapports.export', [$cle, 'pdf', 'du' => $du, 'au' => $au]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
                @endif
            </div>
        @endforeach
    </div>
@endsection
