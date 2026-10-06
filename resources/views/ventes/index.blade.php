@extends('layouts.app')
@section('titre', 'Historique des ventes')
@section('contenu')
    <div class="entete-page">
        <h1>Historique des ventes</h1>
        <div class="d-flex gap-2">
            <a href="{{ route('ventes.export', request()->query() + ['format' => 'excel']) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            <a href="{{ route('ventes.export', request()->query() + ['format' => 'pdf']) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            @can('ventes.creer')<a href="{{ route('ventes.create') }}" class="btn btn-primary"><i class="bi bi-cart-plus me-1"></i>Nouvelle vente</a>@endcan
        </div>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        <div class="col-md">
            <label class="form-label small text-doux mb-1" for="q">Recherche</label>
            <input name="q" id="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="N° de vente, client, téléphone">
        </div>
        @include('partials.periode')
        <div class="col-md-auto">
            <label class="form-label small text-doux mb-1" for="statut">Paiement</label>
            <select name="statut" id="statut" class="form-select form-select-sm">
                <option value="">Toutes</option>
                <option value="payee" @selected(request('statut') === 'payee')>Payées</option>
                <option value="credit" @selected(request('statut') === 'credit')>Avec reste à payer</option>
                <option value="annulee" @selected(request('statut') === 'annulee')>Annulées</option>
            </select>
        </div>
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button> <a href="{{ route('ventes.index') }}" class="btn btn-sm btn-light">Effacer</a></div>
    </form>

    <div class="bloc">
        <div class="bloc-entete">
            <span class="text-doux">{{ $ventes->total() }} vente(s)</span>
            <span>Total : <strong class="montant">{{ gnf($totaux->ttc) }}</strong> · Reste à encaisser : <strong class="montant">{{ gnf($totaux->ttc - $totaux->paye) }}</strong></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th>N°</th><th>Date</th><th>Client</th><th class="text-end">Total</th><th class="text-end">Reste</th><th>État</th><th>Vendeur</th></tr></thead>
                <tbody>
                @forelse ($ventes as $v)
                    <tr>
                        <td class="text-nowrap"><a href="{{ route('ventes.show', $v) }}" class="fw-semibold">{{ $v->numero }}</a></td>
                        <td>{{ $v->date_vente->format('d/m/Y H:i') }}</td>
                        <td>{{ $v->client?->nomComplet() ?? 'Client comptoir' }}</td>
                        <td class="text-end montant">{{ gnf($v->total_ttc) }}</td>
                        <td class="text-end montant">{{ $v->resteAPayer() ? gnf($v->resteAPayer()) : '—' }}</td>
                        <td>@include('partials.etat-vente', ['vente' => $v])</td>
                        <td class="text-doux">{{ $v->vendeur?->nomComplet() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide"><i class="bi bi-receipt"></i>Aucune vente ne correspond à ces critères.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $ventes->links() }}</div>
@endsection
