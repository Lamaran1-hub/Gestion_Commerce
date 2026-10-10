@extends('layouts.app')
@section('titre', 'Produits')
@section('contenu')
    @php($voirAchat = auth()->user()->aPermission('produits.prix_achat'))
    <div class="entete-page">
        <div>
            <h1>Produits</h1>
            <div class="text-doux">{{ $produits->total() }} produit(s)@if ($voirAchat) · valeur du stock <strong class="montant">{{ gnf($valeurStock) }}</strong>@endif
                @if ($nbAlertes) · <a href="{{ route('produits.index', ['etat' => 'alerte']) }}" class="text-warning-emphasis">{{ $nbAlertes }} à réapprovisionner</a>@endif
                @if ($nbMargesFaibles) · <a href="{{ route('produits.index', ['etat' => 'marge']) }}" class="text-danger">{{ $nbMargesFaibles }} à marge faible</a>@endif</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('produits.export', ['excel'] + request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
            <a href="{{ route('produits.export', ['pdf'] + request()->query()) }}" class="btn btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
            @can('produits.gerer')
                <a href="{{ route('categories.index') }}" class="btn btn-outline-primary"><i class="bi bi-tags me-1"></i>Catégories</a>
                @if (fonction('import_catalogue'))<a href="{{ route('produits.import') }}" class="btn btn-outline-primary"><i class="bi bi-upload me-1"></i>Importer</a>@endif
                @if (fonction('etiquettes'))<a href="{{ route('produits.etiquettes') }}" class="btn btn-outline-primary"><i class="bi bi-upc-scan me-1"></i>Étiquettes</a>@endif
                <a href="{{ route('produits.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouveau produit</a>
            @endcan
        </div>
    </div>
    <form class="bloc bloc-corps row g-2 align-items-end mb-3">
        <div class="col-md"><label class="form-label small text-doux mb-1" for="q">Recherche</label>
            <input name="q" id="q" value="{{ request('q') }}" class="form-control form-control-sm" placeholder="Désignation ou code-barres"></div>
        <div class="col-md-3"><label class="form-label small text-doux mb-1" for="categorie_id">Catégorie</label>
            <select name="categorie_id" id="categorie_id" class="form-select form-select-sm"><option value="">Toutes</option>
                @foreach ($categories as $c)<option value="{{ $c->id }}" @selected(request('categorie_id') == $c->id)>{{ $c->nom }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label small text-doux mb-1" for="etat">Stock</label>
            <select name="etat" id="etat" class="form-select form-select-sm"><option value="">Tous</option>
                <option value="alerte" @selected(request('etat') === 'alerte')>Sous le seuil</option>
                <option value="rupture" @selected(request('etat') === 'rupture')>En rupture</option>
                <option value="inactif" @selected(request('etat') === 'inactif')>Désactivés</option>
                @can('produits.prix_achat')<option value="marge" @selected(request('etat') === 'marge')>Marge faible (moins de {{ rtrim(rtrim(number_format(\App\Support\Marges::seuil(), 1, ',', ''), '0'), ',') }} %)</option>@endcan</select></div>
        <div class="col-md-auto"><button class="btn btn-sm btn-primary">Filtrer</button> <a href="{{ route('produits.index') }}" class="btn btn-sm btn-light">Effacer</a></div>
    </form>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table table-hover">
                <thead><tr><th></th><th>Désignation</th><th>Catégorie</th>@if ($voirAchat)<th class="text-end">Prix d'achat</th>@endif
                    <th class="text-end">Prix de vente</th><th class="text-end">Stock</th><th></th></tr></thead>
                <tbody>
                @forelse ($produits as $p)
                    <tr class="{{ $p->actif ? '' : 'opacity-50' }}">
                        <td style="width:52px">@if ($p->imageUrl())<img src="{{ $p->imageUrl() }}" alt="" class="vignette">@else<div class="vignette d-grid" style="place-items:center"><i class="bi bi-box text-doux"></i></div>@endif</td>
                        <td><a href="{{ route('produits.show', $p) }}" class="fw-semibold text-reset">{{ $p->designation }}</a>
                            <div class="small text-doux">{{ $p->code_barre ?: 'Sans code-barres' }}{{ $p->fournisseur ? ' · '.$p->fournisseur->nom : '' }}</div></td>
                        <td>{{ $p->categorie?->nom ?? '—' }}</td>
                        @if ($voirAchat)<td class="text-end montant text-doux">{{ gnf($p->prix_achat) }}</td>@endif
                        <td class="text-end montant fw-semibold">{{ gnf($p->prix_vente) }}</td>
                        <td class="text-end"><span class="etat etat-{{ $p->etatStock() }}">{{ qte($p->stock) }} {{ $p->unite }}</span></td>
                        <td class="text-end text-nowrap">
                            @can('approvisionnements.gerer')<a href="{{ route('approvisionnements.create', ['produit_id' => $p->id]) }}" class="btn btn-sm btn-light" title="Approvisionner"><i class="bi bi-truck"></i></a>@endcan
                            @can('produits.gerer')<a href="{{ route('produits.edit', $p) }}" class="btn btn-sm btn-light" title="Modifier"><i class="bi bi-pencil"></i></a>@endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="vide"><i class="bi bi-box-seam"></i>Aucun produit.
                        @can('produits.gerer') <a href="{{ route('produits.create') }}">Ajouter le premier produit</a>@endcan</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $produits->links() }}</div>
@endsection
