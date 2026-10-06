@extends('layouts.app')
@section('titre', 'Devis / proforma')
@section('contenu')
    <div class="entete-page">
        <div><h1>Devis / factures proforma</h1><div class="text-doux">Prix garantis jusqu'à la date de validité ; aucun produit ne sort du stock avant la vente.</div></div>
        @can('ventes.creer')<a href="{{ route('ventes.create', ['proforma' => 1]) }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle facture proforma</a>@endcan
    </div>
    <div class="d-flex flex-wrap gap-2 mb-3">
        @php
            $nbEnLigne = \App\Models\Devis::commandesATraiter()->count();
        @endphp
        @foreach (['' => 'Tous', 'en_ligne' => 'Commandes en ligne à traiter'.($nbEnLigne ? " ($nbEnLigne)" : ''), 'en_cours' => 'En cours', 'expire' => 'Expirés', 'converti' => 'Transformés en vente', 'annule' => 'Annulés'] as $k => $l)
            <a href="{{ route('devis.index', array_filter(['etat' => $k])) }}" class="btn btn-sm {{ request('etat', '') === $k ? 'btn-primary' : 'btn-outline-primary' }}">{{ $l }}</a>
        @endforeach
    </div>
    <div class="bloc"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>N°</th><th>Date</th><th>Client</th><th class="text-end">Montant</th><th>Valable jusqu'au</th><th>État</th><th>Par</th></tr></thead>
        <tbody>
        @forelse ($devis as $d)
            <tr><td><a href="{{ route('devis.show', $d) }}" class="fw-semibold">{{ $d->numero }}</a></td>
                <td>{{ $d->date_devis->format('d/m/Y') }}</td><td>{{ $d->nomClient() }}@if ($d->origine === 'vitrine') <span class="badge text-bg-success"><i class="bi bi-shop-window"></i> Vitrine</span>@endif</td>
                <td class="text-end montant">{{ gnf($d->total_ttc) }}@if ($d->acompte)<div class="small text-success text-nowrap">acompte {{ gnf($d->acompte) }}</div>@endif</td><td>{{ $d->valable_jusqu_au->format('d/m/Y') }}</td>
                <td><span class="etat {{ $d->classeEtat() }}">{{ $d->libelleEtat() }}</span></td>
                <td class="small text-doux">{{ $d->auteur?->prenom }}</td></tr>
        @empty
            <tr><td colspan="7" class="vide"><i class="bi bi-file-earmark-text"></i>Aucun devis. Cliquez sur « Nouvelle facture proforma » (ou, à la caisse, sur « Devis (proforma) »).</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $devis->links() }}</div>
@endsection
