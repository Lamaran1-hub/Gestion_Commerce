@extends('layouts.app')
@section('titre', $libelle)
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $libelle }}</h1><div class="text-doux">Journal du {{ $du->format('d/m/Y') }} au {{ $au->format('d/m/Y') }}</div></div>
        <a href="{{ route('tresorerie.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Trésorerie</a>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        @include('partials.periode', ['du' => $du->toDateString(), 'au' => $au->toDateString()])
        <div class="col-md-auto"><button class="btn btn-primary">Afficher</button></div>
    </form>
    <div class="bloc"><div class="table-responsive">
        <table class="table mb-0">
            <thead><tr><th>Date</th><th>Opération</th><th class="text-end">Entrée</th><th class="text-end">Sortie</th><th class="text-end">Solde</th></tr></thead>
            <tbody>
                <tr class="fw-semibold"><td>{{ $du->format('d/m/Y') }}</td><td>Solde au début</td><td></td><td></td><td class="text-end montant">{{ gnf($soldeDebut) }}</td></tr>
                @foreach ($lignes as $l)
                    <tr class="{{ isset($l['constat']) ? 'table-light fw-semibold' : '' }}"><td class="small text-nowrap">{{ $l['date']->format('d/m/Y H:i') }}</td><td>{{ $l['libelle'] }}</td>
                        <td class="text-end montant text-success">{{ $l['entree'] ? gnf($l['entree']) : '' }}</td>
                        <td class="text-end montant text-danger">{{ $l['sortie'] ? gnf($l['sortie']) : '' }}</td>
                        <td class="text-end montant">{{ gnf($l['solde']) }}</td></tr>
                @endforeach
            </tbody>
            <tfoot><tr class="fw-bold"><td colspan="2">Solde à la fin</td>
                <td class="text-end montant">{{ gnf($lignes->sum('entree')) }}</td><td class="text-end montant">{{ gnf($lignes->sum('sortie')) }}</td>
                <td class="text-end montant">{{ gnf($soldeFin) }}</td></tr></tfoot>
        </table>
    </div></div>
@endsection
