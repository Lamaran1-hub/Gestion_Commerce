@extends('layouts.app')
@section('titre', 'Formules')
@section('contenu')
    <div class="entete-page"><h1>Formules d'abonnement</h1><a href="{{ route('admin.plans.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouvelle formule</a></div>
    <div class="bloc">
        <table class="table">
            <thead><tr><th>Formule</th><th class="text-end">Prix mensuel</th><th class="text-end">Utilisateurs max</th><th class="text-end">Produits max</th><th class="text-end">Points de vente max</th><th>Fonctions</th><th class="text-end">Boutiques</th><th>État</th><th></th></tr></thead>
            <tbody>
            @foreach ($plans as $p)
                <tr><td class="fw-semibold">{{ $p->nom }}<div class="small text-doux">{{ $p->description }}</div></td>
                    <td class="text-end montant">{{ gnf($p->prix_mensuel) }}</td><td class="text-end">{{ $p->max_utilisateurs ?? 'Illimité' }}</td>
                    <td class="text-end">{{ $p->max_produits ?? 'Illimité' }}</td><td class="text-end">{{ $p->max_boutiques ?? 'Illimité' }}</td>
                    <td class="small">{{ $p->fonctions === null ? 'Toutes' : count($p->fonctions).' / '.count(config('gestion.fonctions')) }}</td><td class="text-end">{{ $p->boutiques_count }}</td>
                    <td><span class="etat {{ $p->actif ? 'etat-ok' : 'etat-neutre' }}">{{ $p->actif ? 'Proposée' : 'Retirée' }}</span></td>
                    <td class="text-end"><a href="{{ route('admin.plans.edit', $p) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a></td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
