@extends('layouts.app')
@section('titre', 'Étiquettes')
@section('contenu')
    <div class="entete-page">
        <div><h1>Étiquettes prix et code-barres</h1>
            <div class="text-doux">Pour planches A4 autocollantes. Les produits sans code-barres reçoivent un code interne, lisible ensuite à la caisse.</div></div>
        <a href="{{ route('produits.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Produits</a>
    </div>
    <form class="bloc bloc-corps row g-2 mb-3">
        <div class="col-md-5"><input name="q" value="{{ request('q') }}" class="form-control" placeholder="Rechercher un produit (nom, code)"></div>
        <div class="col-md-4"><select name="categorie_id" class="form-select" aria-label="Catégorie"><option value="">Toutes les catégories</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('categorie_id') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
        <div class="col-md-auto"><button class="btn btn-outline-primary">Filtrer</button></div>
    </form>
    <form method="post" action="{{ route('produits.etiquettes.imprimer') }}" target="_blank" data-sans-confirmation>
        @csrf
        <div class="bloc">
            <div class="bloc-entete flex-wrap gap-2">
                <div class="d-flex gap-2 align-items-center flex-wrap">
                    <select name="format" class="form-select form-select-sm" style="width:auto" aria-label="Format de planche">
                        @foreach ($formats as $k => [$lib])<option value="{{ $k }}">{{ $k }} par page — {{ $lib }}</option>@endforeach
                    </select>
                    <div class="form-check mb-0"><input type="hidden" name="prix" value="0"><input type="checkbox" name="prix" value="1" id="prix" class="form-check-input" checked>
                        <label for="prix" class="form-check-label small">Afficher le prix</label></div>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-light" data-remplir="stock">Autant que le stock</button>
                    <button type="button" class="btn btn-sm btn-light" data-remplir="1">1 chacun</button>
                    <button type="button" class="btn btn-sm btn-light" data-remplir="0">Tout à 0</button>
                    <button class="btn btn-sm btn-primary"><i class="bi bi-printer me-1"></i>Imprimer</button>
                </div>
            </div>
            <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
                <thead><tr><th>Produit</th><th>Code-barres</th><th class="text-end">Prix</th><th class="text-end">Stock</th><th style="width:120px">Étiquettes</th></tr></thead>
                <tbody>
                @forelse ($produits as $p)
                    <tr><td>{{ $p->designation }}</td>
                        <td>{!! $p->code_barre ? e($p->code_barre) : '<span class="small text-doux">sera créé</span>' !!}</td>
                        <td class="text-end montant">{{ gnf($p->prix_vente) }}</td><td class="text-end">{{ qte($p->stock) }}</td>
                        <td><input type="number" min="0" max="500" name="quantites[{{ $p->id }}]" value="0" data-stock="{{ max(0, (int) ceil($p->stock)) }}"
                                   class="form-control form-control-sm text-end" aria-label="Nombre d'étiquettes pour {{ $p->designation }}"></td></tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucun produit.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
    </form>
@endsection
@push('scripts')
<script>
document.querySelectorAll('[data-remplir]').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('input[data-stock]').forEach(i => i.value = b.dataset.remplir === 'stock' ? Math.min(500, i.dataset.stock) : b.dataset.remplir);
}));
</script>
@endpush
