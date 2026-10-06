@extends('layouts.app')
@section('titre', $titre)
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $titre }}</h1>@if ($sousTitre)<div class="text-doux">{{ $sousTitre }}</div>@endif</div>
        <div class="d-flex gap-2 no-print">
            <a href="{{ route('rapports.index', request()->only('du', 'au')) }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Rapports</a>
            {{-- Rapport (rapports/{rapport}/{format}) ou export d'une liste (?format=…) : mêmes boutons de téléchargement --}}
            @php($versFormat = fn ($f) => request()->route('rapport') ? route('rapports.export', [request()->route('rapport'), $f] + request()->only('du', 'au')) : request()->fullUrlWithQuery(['format' => $f]))
            <a href="{{ $versFormat('excel') }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            <a href="{{ $versFormat('pdf') }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        </div>
    </div>
    <div class="bloc"><div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead><tr>@foreach ($colonnes as $cle => $libelle)<th class="{{ in_array($cle, $montants) ? 'text-end' : '' }}">{{ $libelle }}</th>@endforeach</tr></thead>
            <tbody>
            @forelse ($lignes as $l)
                <tr>@foreach ($colonnes as $cle => $libelle)
                    @php($v = $l[$cle] ?? null)
                    @if (in_array($cle, $montants))<td class="text-end montant {{ $v < 0 ? 'text-danger' : '' }}">{{ gnf($v) }}</td>
                    @elseif (is_float($v))<td>{{ qte($v) }}</td>
                    @else<td>{{ $v }}</td>@endif
                @endforeach</tr>
            @empty
                <tr><td colspan="{{ count($colonnes) }}" class="vide">Aucune donnée sur cette période.</td></tr>
            @endforelse
            </tbody>
            @if ($totaux)
                <tfoot><tr class="fw-bold">@foreach ($colonnes as $cle => $libelle)
                    <td class="{{ in_array($cle, $montants) ? 'text-end montant' : '' }}">{{ $loop->first ? 'Total' : (array_key_exists($cle, $totaux) ? (in_array($cle, $montants) ? gnf($totaux[$cle]) : $totaux[$cle]) : '') }}</td>
                @endforeach</tr></tfoot>
            @endif
        </table>
    </div></div>
@endsection
