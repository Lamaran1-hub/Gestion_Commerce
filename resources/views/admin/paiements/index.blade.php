@extends('layouts.app')
@section('titre', 'Paiements de licences')
@section('contenu')
    <div class="entete-page">
        <div><h1>Paiements de licences</h1><div class="text-doux">{{ $nombre }} paiement(s) · total <strong class="montant">{{ gnf($total) }}</strong></div></div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.paiements.export', ['excel'] + request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            <a href="{{ route('admin.paiements.export', ['pdf'] + request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
        </div>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        @include('partials.periode')
        <div class="col-md-3"><label class="form-label small text-doux mb-1" for="boutique_id">Client</label>
            <select name="boutique_id" id="boutique_id" class="form-select form-select-sm"><option value="">Tous</option>
                @foreach ($boutiques as $b)<option value="{{ $b->id }}" @selected(request('boutique_id') == $b->id)>{{ $b->nom }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-doux mb-1" for="mode">Mode</label>
            <select name="mode" id="mode" class="form-select form-select-sm"><option value="">Tous</option>
                @foreach (config('gestion.modes_paiement') as $k => $l)<option value="{{ $k }}" @selected(request('mode') === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    </form>
    @if ($parMode->isNotEmpty())
        <div class="d-flex flex-wrap gap-2 mb-3">
            @foreach ($parMode as $mode => $t)<span class="raccourci">{{ config('gestion.modes_paiement')[$mode] ?? $mode }} : <strong class="montant">{{ gnf($t) }}</strong></span>@endforeach
        </div>
    @endif
    <div class="bloc"><div class="table-responsive"><table class="table table-hover">
        <thead><tr><th>N°</th><th>Payé le</th><th>Client</th><th>Formule</th><th>Période couverte</th><th>Mode</th><th class="text-end">Montant</th><th></th></tr></thead>
        <tbody>
        @forelse ($paiements as $p)
            <tr><td class="fw-semibold">{{ $p->numero }}</td><td>{{ $p->paye_le->format('d/m/Y') }}</td>
                <td>@if ($p->boutique)<a href="{{ route('admin.boutiques.show', $p->boutique) }}">{{ $p->boutique->nom }}</a>@endif</td>
                <td>{{ $p->plan?->nom }}</td><td class="small">{{ $p->libellePeriode() }}</td>
                <td class="small">{{ $p->libelleMode() }}@if ($p->reference)<div class="text-doux">{{ $p->reference }}</div>@endif</td>
                <td class="text-end montant fw-semibold">{{ gnf($p->montant) }}</td>
                <td class="text-end"><a href="{{ route('admin.paiements.recu', $p) }}" target="_blank" class="btn btn-sm btn-light" title="Reçu PDF"><i class="bi bi-receipt"></i></a></td></tr>
        @empty
            <tr><td colspan="8" class="vide"><i class="bi bi-cash-coin"></i>Aucun paiement sur cette période.</td></tr>
        @endforelse
        </tbody>
    </table></div></div>
    <div class="mt-3">{{ $paiements->links() }}</div>
@endsection
