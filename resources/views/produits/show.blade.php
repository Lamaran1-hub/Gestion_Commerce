@extends('layouts.app')
@section('titre', $produit->designation)
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $produit->designation }}</h1>
            <div class="text-doux">{{ $produit->categorie?->nom ?? 'Sans catégorie' }} · {{ $produit->code_barre ?: 'sans code-barres' }}</div></div>
        <div class="d-flex gap-2">
            @can('approvisionnements.gerer')<a href="{{ route('approvisionnements.create', ['produit_id' => $produit->id]) }}" class="btn btn-outline-primary"><i class="bi bi-truck me-1"></i>Approvisionner</a>@endcan
            @can('produits.gerer')<a href="{{ route('produits.edit', $produit) }}" class="btn btn-primary"><i class="bi bi-pencil me-1"></i>Modifier</a>@endcan
        </div>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Stock</div><div class="valeur"><span class="etat etat-{{ $produit->etatStock() }} fs-6">{{ qte($produit->stock) }} {{ $produit->unite }}</span></div></div></div>
        <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Prix de vente</div><div class="valeur montant">{{ gnf($produit->prix_vente) }}</div></div></div>
        @can('produits.prix_achat')
            <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Prix d'achat</div><div class="valeur montant">{{ gnf($produit->prix_achat) }}</div></div></div>
            <div class="col-md-3"><div class="bloc kpi"><div class="etiquette">Valeur en stock</div><div class="valeur montant">{{ gnf($produit->valeurStock()) }}</div></div></div>
        @endcan
    </div>
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
@endsection
