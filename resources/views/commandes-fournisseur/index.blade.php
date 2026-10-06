@extends('layouts.app')
@section('titre', 'Commandes fournisseurs')
@section('contenu')
    <div class="entete-page">
        <div><h1>Commandes fournisseurs</h1>
            <div class="text-doux">{{ $nbEnAttente ? $nbEnAttente.' commande(s) en attente de livraison' : 'Aucune commande en attente' }}</div></div>
        <a href="{{ route('stock.a-commander') }}" class="btn btn-primary"><i class="bi bi-cart-check me-1"></i>Préparer une commande</a>
    </div>
    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link {{ $toutes ? '' : 'active' }}" href="{{ route('commandes-fournisseur.index') }}">En attente @if ($nbEnAttente)<span class="badge text-bg-light">{{ $nbEnAttente }}</span>@endif</a></li>
        <li class="nav-item"><a class="nav-link {{ $toutes ? 'active' : '' }}" href="{{ route('commandes-fournisseur.index', ['vue' => 'toutes']) }}">Toutes</a></li>
    </ul>
    <div class="bloc">
        <table class="table mb-0">
            <thead><tr><th>Commande</th><th>Fournisseur</th><th>Livraison prévue</th><th class="text-end">Estimé</th><th>État</th></tr></thead>
            <tbody>
            @forelse ($commandes as $c)
                <tr class="{{ $c->enRetard() ? 'table-danger' : '' }}">
                    <td><a href="{{ route('commandes-fournisseur.show', $c) }}" class="fw-semibold">{{ $c->numero }}</a>
                        <div class="small text-doux">{{ $c->date_commande->format('d/m/Y') }} · {{ $c->lignes_count }} produit(s)</div></td>
                    <td>{{ $c->fournisseur?->nom ?? '—' }}</td>
                    <td class="small">{{ $c->livraison_prevue_le?->format('d/m/Y') ?? '—' }}</td>
                    <td class="text-end montant">{{ gnf($c->total_estime) }}</td>
                    <td><span class="etat {{ $c->classeEtat() }}">{{ $c->libelleEtat() }}{{ $c->enRetard() ? ' · en retard' : '' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="vide"><i class="bi bi-clipboard"></i>{{ $toutes ? 'Aucune commande pour l\'instant.' : 'Aucune commande en attente. Préparez-en une depuis « À commander ».' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $commandes->links() }}</div>
@endsection
