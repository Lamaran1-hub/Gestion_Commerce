@extends('layouts.app')
@section('titre', 'Livraisons')
@section('contenu')
    <div class="entete-page">
        <div><h1>Livraisons</h1>
            <div class="text-doux">{{ $nbALivrer ? $nbALivrer.' livraison(s) à faire' : 'Aucune livraison en attente' }}</div></div>
    </div>
    <ul class="nav nav-pills mb-3">
        <li class="nav-item"><a class="nav-link {{ $faites ? '' : 'active' }}" href="{{ route('livraisons.index') }}">À faire @if ($nbALivrer)<span class="badge text-bg-light">{{ $nbALivrer }}</span>@endif</a></li>
        <li class="nav-item"><a class="nav-link {{ $faites ? 'active' : '' }}" href="{{ route('livraisons.index', ['vue' => 'livrees']) }}">Livrées</a></li>
    </ul>
    <div class="bloc">
        <table class="table mb-0">
            <thead><tr><th>Vente</th><th>Client et adresse</th><th>{{ $faites ? 'Livrée' : 'Prévue' }}</th><th class="text-end">À encaisser</th><th>État</th></tr></thead>
            <tbody>
            @forelse ($ventes as $v)
                <tr class="{{ $v->livraisonEnRetard() ? 'table-danger' : '' }}">
                    <td><a href="{{ route('ventes.show', $v) }}#livraison" class="fw-semibold">{{ $v->numero }}</a><div class="small text-doux">{{ $v->date_vente->format('d/m/Y') }}</div></td>
                    <td>{{ $v->client?->nomComplet() ?? 'Client comptoir' }}<div class="small text-doux">{{ $v->livraison_adresse }}{{ $v->livraison_contact ? ' · '.$v->livraison_contact : '' }}</div></td>
                    <td class="small text-nowrap">
                        @if ($faites){{ $v->livree_le?->format('d/m/Y H:i') }}<div class="text-doux">à {{ $v->livree_a }}</div>
                        @else{{ $v->livraison_prevue_le?->format('d/m/Y') ?? '—' }}@if ($v->livreur)<div class="text-doux">{{ $v->livreur }}</div>@endif
                        @endif</td>
                    <td class="text-end montant {{ $v->resteAPayer() ? 'text-danger fw-semibold' : 'text-doux' }}">{{ $v->resteAPayer() ? gnf($v->resteAPayer()) : '—' }}</td>
                    <td><span class="etat {{ $v->livraison === 'livree' ? 'etat-ok' : ($v->livraisonEnRetard() ? 'etat-rupture' : 'etat-alerte') }}">{{ $v->libelleLivraison() }}{{ $v->livraisonEnRetard() ? ' · en retard' : '' }}</span></td>
                </tr>
            @empty
                <tr><td colspan="5" class="vide"><i class="bi bi-truck"></i>{{ $faites ? 'Aucune livraison faite pour l\'instant.' : 'Rien à livrer. Pour livrer une vente, ouvrez-la et choisissez « Livrer cette vente chez le client ».' }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="mt-3">{{ $ventes->links() }}</div>
@endsection
