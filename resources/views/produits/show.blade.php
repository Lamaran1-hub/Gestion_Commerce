@extends('layouts.app')
@section('titre', $produit->designation)
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $produit->designation }}</h1>
            <div class="text-doux">@if ($produit->est_kit)<span class="badge bg-primary me-1"><i class="bi bi-boxes me-1"></i>Kit</span>@endif{{ $produit->categorie?->nom ?? 'Sans catégorie' }} · {{ $produit->code_barre ?: 'sans code-barres' }}</div></div>
        <div class="d-flex gap-2">
            @if (! $produit->est_kit) @can('approvisionnements.gerer')<a href="{{ route('approvisionnements.create', ['produit_id' => $produit->id]) }}" class="btn btn-outline-primary"><i class="bi bi-truck me-1"></i>Approvisionner</a>@endcan @endif
            @can('produits.gerer')<a href="{{ route('produits.edit', $produit) }}" class="btn btn-primary"><i class="bi bi-pencil me-1"></i>Modifier</a>@endcan
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">{{ $produit->est_kit ? 'Kits formables' : 'Stock' }}</div><div class="valeur"><span class="etat etat-{{ $produit->etatStock() }} fs-6">{{ qte($produit->stock) }} {{ $produit->unite }}</span></div></div></div>
        <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Prix de vente</div><div class="valeur montant">{{ gnf($produit->prix_vente) }}</div></div></div>
        @can('produits.prix_achat')
            <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Prix d'achat</div><div class="valeur montant">{{ gnf($produit->prix_achat) }}</div></div></div>
            @unless ($produit->est_kit)<div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Valeur en stock</div><div class="valeur montant">{{ gnf($produit->valeurStock()) }}</div></div></div>@endunless
        @endcan
    </div>
    @if ($produit->est_kit)
        {{-- Composition : le kit est disponible tant que chacun de ses produits l'est --}}
        <div class="bloc mb-3" id="compositionKit">
            <div class="bloc-entete"><h2 class="mb-0"><i class="bi bi-boxes me-1"></i>Composition du kit</h2>
                <span class="small text-doux">vendre 1 kit sort ces quantités du stock</span></div>
            <div class="table-responsive"><table class="table mb-0 align-middle">
                <thead><tr><th>Produit</th><th class="text-end">Dans 1 kit</th><th class="text-end d-none d-sm-table-cell">En stock</th><th class="text-end">Kits possibles</th></tr></thead>
                <tbody>
                @php
                    $possiblesPar = fn ($c) => $c->quantite > 0 ? (int) floor(max(0, $c->composant?->stock ?? 0) / $c->quantite + 1e-9) : 0;
                    $limite = $produit->composants->min($possiblesPar);
                @endphp
                @foreach ($produit->composants as $c)
                    @php
                        $possibles = $possiblesPar($c);
                    @endphp
                    <tr><td><a href="{{ route('produits.show', $c->composant_id) }}" class="fw-semibold">{{ $c->composant?->designation }}</a>
                            @if ($c->composant && (! $c->composant->actif || $c->composant->trashed()))<span class="etat etat-rupture ms-1">retiré de la vente</span>@endif
                            <div class="small text-doux d-sm-none">{{ qte($c->composant?->stock ?? 0) }} en stock</div></td>
                        <td class="text-end text-nowrap">{{ qte($c->quantite) }} {{ $c->composant?->unite }}</td>
                        <td class="text-end text-nowrap d-none d-sm-table-cell">{{ qte($c->composant?->stock ?? 0) }}</td>
                        <td class="text-end {{ $possibles == $limite ? 'fw-bold text-danger' : '' }}">{{ $possibles }}@if ($possibles == $limite && $produit->composants->count() > 1)<div class="small fw-normal">c'est lui qui limite</div>@endif</td></tr>
                @endforeach
                </tbody>
            </table></div>
        </div>
    @elseif ($dansKits->isNotEmpty())
        <div class="alert alert-info small"><i class="bi bi-boxes me-1"></i>Ce produit entre dans
            @foreach ($dansKits as $k)<a href="{{ route('produits.show', $k) }}" class="fw-semibold">{{ $k->designation }}</a>{{ $loop->last ? '' : ', ' }}@endforeach
            : vendre ce kit sort aussi ce produit du stock.</div>
    @endif
    <div class="bloc mb-3" id="historiquePrix">
        <div class="bloc-entete"><h2 class="mb-0">Historique des prix</h2><span class="small text-doux">les 15 derniers changements</span></div>
        <div class="table-responsive">
            <table class="table mb-0 align-middle">
                <thead><tr><th class="d-none d-sm-table-cell">Date</th><th>Prix</th><th class="text-end d-none d-sm-table-cell">Avant</th><th class="text-end">Après</th><th class="d-none d-md-table-cell">Origine</th></tr></thead>
                <tbody>
                @forelse ($historiquePrix as $h)
                    @php($variation = $h->variation())
                    {{-- Sur téléphone : date sous le libellé, ancien prix sous le nouveau --}}
                    <tr><td class="small text-nowrap d-none d-sm-table-cell">{{ $h->created_at->format('d/m/Y H:i') }}</td>
                        <td>{{ $h->libelleChamp() }}<div class="small text-doux d-sm-none">{{ $h->created_at->format('d/m/Y H:i') }}</div>
                            <div class="small text-doux d-md-none">{{ $h->origine }}{{ $h->auteur ? ' · '.$h->auteur->nomComplet() : '' }}</div></td>
                        <td class="text-end montant text-doux text-nowrap d-none d-sm-table-cell">{{ $h->ancien !== null ? gnf($h->ancien) : '—' }}</td>
                        <td class="text-end montant fw-semibold text-nowrap">{{ $h->nouveau !== null ? gnf($h->nouveau) : '—' }}
                            @if ($h->ancien !== null)<div class="small text-doux fw-normal d-sm-none">avant {{ gnf($h->ancien) }}</div>@endif
                            @if ($variation !== null)<div class="small {{ $variation < 0 ? 'text-danger' : 'text-success' }}">{{ $variation > 0 ? '+' : '' }}{{ number_format($variation, 1, ',', ' ') }} %</div>@endif</td>
                        <td class="d-none d-md-table-cell small">{{ $h->origine }}<div class="text-doux">{{ $h->auteur?->nomComplet() }}</div></td></tr>
                @empty
                    <tr><td colspan="5" class="vide">Aucun changement de prix depuis l'activation de l'historique.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if ($produit->est_kit)
        <p class="small text-doux"><i class="bi bi-info-circle me-1"></i>Un kit n'a pas d'historique de stock propre : chaque vente apparaît dans l'historique de ses produits.</p>
    @else
    <div class="bloc">
        <div class="bloc-entete"><h2 class="mb-0">Historique du stock</h2></div>
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Date</th><th>Opération</th><th>Détail</th><th class="text-end">Quantité</th><th class="text-end">Stock après</th><th>Par</th></tr></thead>
                <tbody>
                @forelse ($mouvements as $m)
                    <tr><td>{{ $m->created_at->format('d/m/Y H:i') }}</td><td>{{ $m->libelle() }}</td><td class="text-doux">{{ $m->motif }}</td>
                        <td class="text-end fw-semibold {{ $m->quantite < 0 ? 'text-danger' : 'text-success' }}">{{ $m->quantite > 0 ? '+' : '' }}{{ qte($m->quantite) }}</td>
                        <td class="text-end">{{ qte($m->stock_apres) }}</td><td class="text-doux">{{ $m->auteur?->nomComplet() }}</td></tr>
                @empty
                    <tr><td colspan="6" class="vide">Aucun mouvement enregistré.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="mt-3">{{ $mouvements->links() }}</div>
    @endif
@endsection
