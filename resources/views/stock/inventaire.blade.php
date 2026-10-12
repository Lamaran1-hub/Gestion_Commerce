@extends('layouts.app')
@section('titre', 'Inventaire')
@section('contenu')
    <div class="entete-page">
        <div><h1>Inventaire</h1><div class="text-doux">Comptez les produits en rayon et saisissez la quantité réelle. Laissez vide ce que vous n'avez pas compté.</div></div>
    </div>
    {{-- Inventaire tournant : quelques produits par jour, tout le stock vérifié en un mois --}}
    <div class="bloc mb-3" id="comptageDuJour">
        <div class="bloc-corps d-flex flex-wrap align-items-center gap-2">
            <i class="bi bi-arrow-repeat fs-4 text-primary"></i>
            <div class="flex-grow-1">
                <div class="fw-semibold">Comptage du jour</div>
                <div class="small text-doux">
                    {{ $couverture['pct'] }} % du stock vérifié ces 30 derniers jours ({{ $couverture['verifies'] }} produit(s) sur {{ $couverture['total'] }})
                    @if ($couverture['aujourdhui']) · {{ $couverture['aujourdhui'] }} compté(s) aujourd'hui @endif
                </div>
                <div class="progress mt-1" style="height:6px;max-width:320px" role="progressbar" aria-label="Part du stock vérifiée" aria-valuenow="{{ $couverture['pct'] }}" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar {{ $couverture['pct'] >= 80 ? 'bg-success' : '' }}" style="width: {{ $couverture['pct'] }}%"></div>
                </div>
            </div>
            @if ($duJour)
                <a href="{{ route('stock.inventaire') }}" class="btn btn-light btn-sm">Tout le stock</a>
            @else
                <a href="{{ route('stock.inventaire', ['tournant' => 1]) }}" class="btn btn-primary btn-sm">Compter les {{ $parJour }} produits du jour</a>
            @endif
        </div>
    </div>
    @if ($duJour)
        <p class="small text-doux">Produits jamais comptés d'abord, puis ceux comptés il y a le plus longtemps ; à égalité, ceux qui valent le plus. Comptez-les en quelques minutes, sans fermer la boutique.</p>
    @else
        <form class="row g-2 mb-3">
            <div class="col-md-5"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Rechercher un produit"></div>
            <div class="col-md-4"><select name="categorie_id" class="form-select" aria-label="Catégorie"><option value="">Toutes les catégories</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('categorie_id') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
            <div class="col-md-auto"><button class="btn btn-light">Afficher</button></div>
        </form>
    @endif
    @if ($duJour && $produits->isEmpty())
        <div class="bloc"><p class="bloc-corps mb-0"><i class="bi bi-check-circle text-success me-1"></i>
            {{ $couverture['aujourdhui'] ? 'Comptage du jour terminé. Revenez demain pour les suivants.' : 'Tout le stock a été vérifié ces 30 derniers jours.' }}</p></div>
    @else
    <form method="post" action="{{ route('stock.ajuster') }}" data-confirmer="Enregistrer l'inventaire ? Les stocks seront corrigés selon vos comptages.">
        @csrf
        @if ($duJour)<input type="hidden" name="tournant" value="1">@endif
        <div class="bloc">
            <div class="table-responsive">
                <table class="table align-middle">
                    <thead><tr><th>Produit</th><th class="text-end d-none d-sm-table-cell">Stock théorique</th><th style="width:150px">Quantité comptée</th><th class="text-end">Écart</th></tr></thead>
                    <tbody>
                    @forelse ($produits as $p)
                        <tr><td>{{ $p->designation }}
                                <div class="small text-doux">
                                    <span class="d-sm-none">Théorique : {{ qte($p->stock) }} {{ $p->unite }} · </span>@if ($p->code_barre){{ $p->code_barre }} · @endif
                                    <span class="text-nowrap">{{ $p->compte_le ? 'compté le '.$p->compte_le->format('d/m/Y') : 'jamais compté' }}</span>
                                </div></td>
                            <td class="text-end text-nowrap d-none d-sm-table-cell">{{ qte($p->stock) }} {{ $p->unite }}</td>
                            <td><input type="number" step="0.01" min="0" inputmode="decimal" name="comptes[{{ $p->id }}]" data-theorique="{{ $p->stock }}" class="form-control form-control-sm text-end compte" aria-label="Quantité comptée pour {{ $p->designation }}">
                                <input type="hidden" name="theoriques[{{ $p->id }}]" value="{{ $p->stock }}"></td>
                            <td class="text-end ecart"></td></tr>
                    @empty
                        <tr><td colspan="4" class="vide">Aucun produit.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="bloc-corps border-top d-flex flex-wrap gap-2 align-items-center">
                <div style="max-width:360px;width:100%">
                    <label class="form-label small text-doux mb-1" for="motif_inventaire">Motif de la correction</label>
                    <select name="motif" id="motif_inventaire" class="form-select" data-autre>
                        @foreach (config('gestion.motifs_inventaire') as $m)<option>{{ $m }}</option>@endforeach
                    </select>
                </div>
                <button class="btn btn-primary ms-auto align-self-end">{{ $duJour ? 'Enregistrer le comptage' : 'Enregistrer l\'inventaire' }}</button>
            </div>
        </div>
    </form>
    @endif
    @unless ($duJour)<div class="mt-3">{{ $produits->links() }}</div>@endunless
@endsection
@push('scripts')
<script>
document.querySelectorAll('.compte').forEach(i => i.addEventListener('input', () => {
    const cell = i.closest('tr').querySelector('.ecart');
    if (i.value === '') { cell.textContent = ''; return; }
    const e = Math.round((parseFloat(i.value) - parseFloat(i.dataset.theorique)) * 100) / 100;
    cell.textContent = (e > 0 ? '+' : '') + e.toLocaleString('fr-FR');
    cell.className = 'text-end ecart fw-semibold ' + (e < 0 ? 'text-danger' : e > 0 ? 'text-success' : 'text-doux');
}));
</script>
@endpush
