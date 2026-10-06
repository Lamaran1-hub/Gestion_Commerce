@extends('layouts.app')
@section('titre', 'Inventaire')
@section('contenu')
    <div class="entete-page">
        <div><h1>Inventaire</h1><div class="text-doux">Comptez les produits en rayon et saisissez la quantité réelle. Laissez vide ce que vous n'avez pas compté.</div></div>
    </div>
    <form class="row g-2 mb-3">
        <div class="col-md-5"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Rechercher un produit"></div>
        <div class="col-md-4"><select name="categorie_id" class="form-select" aria-label="Catégorie"><option value="">Toutes les catégories</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('categorie_id') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-light">Afficher</button></div>
    </form>
    <form method="post" action="{{ route('stock.ajuster') }}" data-confirmer="Enregistrer l'inventaire ? Les stocks seront corrigés selon vos comptages.">
        @csrf
        <div class="bloc">
            <div class="table-responsive">
                <table class="table">
                    <thead><tr><th>Produit</th><th class="text-end">Stock théorique</th><th style="width:170px">Quantité comptée</th><th class="text-end">Écart</th></tr></thead>
                    <tbody>
                    @forelse ($produits as $p)
                        <tr><td>{{ $p->designation }}<div class="small text-doux">{{ $p->code_barre }}</div></td>
                            <td class="text-end">{{ qte($p->stock) }} {{ $p->unite }}</td>
                            <td><input type="number" step="0.01" min="0" name="comptes[{{ $p->id }}]" data-theorique="{{ $p->stock }}" class="form-control form-control-sm text-end compte" aria-label="Quantité comptée pour {{ $p->designation }}"></td>
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
                <button class="btn btn-primary ms-auto align-self-end">Enregistrer l'inventaire</button>
            </div>
        </div>
    </form>
    <div class="mt-3">{{ $produits->links() }}</div>
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
