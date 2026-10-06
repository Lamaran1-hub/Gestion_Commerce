@extends('layouts.app')
@section('titre', 'Mouvements de stock')
@section('contenu')
    <div class="entete-page"><h1>Mouvements de stock</h1></div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        <div class="col-md-3"><label class="form-label small text-doux mb-1" for="type">Opération</label>
            <select name="type" id="type" class="form-select form-select-sm"><option value="">Toutes</option>
                @foreach ($types as $cle => $lib)<option value="{{ $cle }}" @selected(request('type') === $cle)>{{ $lib }}</option>@endforeach</select></div>
        @include('partials.periode')
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button></div>
    </form>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Produit</th><th>Opération</th><th>Détail</th><th class="text-end">Quantité</th><th class="text-end">Stock après</th><th>Par</th></tr></thead>
                <tbody>
                @forelse ($mouvements as $m)
                    <tr><td>{{ $m->created_at->format('d/m/Y H:i') }}</td>
                        <td><a href="{{ route('produits.show', $m->produit_id) }}">{{ $m->produit?->designation }}</a></td>
                        <td>{{ $m->libelle() }}</td><td class="text-doux">{{ $m->motif }}</td>
                        <td class="text-end fw-semibold {{ $m->quantite < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantite > 0 ? '+' : '' }}{{ qte($m->quantite) }}</td>
                        <td class="text-end">{{ qte($m->stock_apres) }}</td><td class="text-doux">{{ $m->auteur?->nomComplet() }}</td></tr>
                @empty
                    <tr><td colspan="7" class="vide">Aucun mouvement sur ces critères.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $mouvements->links() }}</div>
@endsection
