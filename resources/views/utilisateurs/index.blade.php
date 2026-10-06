@extends('layouts.app')
@section('titre', 'Utilisateurs')
@section('contenu')
    <div class="entete-page">
        <div><h1>Utilisateurs</h1>
            <div class="text-doux">{{ $utilisateurs->where('actif', true)->count() }} compte(s) actif(s){{ $max ? ' sur '.$max.' inclus dans votre formule' : '' }}</div></div>
        <div class="d-flex gap-2"><a href="{{ route('roles.index') }}" class="btn btn-outline-primary"><i class="bi bi-shield-lock me-1"></i>Rôles et droits</a>
            <a href="{{ route('utilisateurs.create') }}" class="btn btn-primary"><i class="bi bi-person-plus me-1"></i>Nouvel utilisateur</a></div>
    </div>
    <div class="bloc">
        <div class="table-responsive">
            <table class="table">
                <thead><tr><th>Nom</th><th>E-mail</th><th>Rôle</th><th>Dernière connexion</th><th>État</th><th></th></tr></thead>
                <tbody>
                @foreach ($utilisateurs as $u)
                    <tr class="{{ $u->actif ? '' : 'opacity-50' }}">
                        <td><div class="d-flex align-items-center gap-2"><span class="avatar">{{ $u->initiales() }}</span><span class="fw-semibold">{{ $u->nomComplet() }}</span>
                            @if ($u->id === auth()->id())<span class="etat etat-neutre">vous</span>@endif</div></td>
                        <td>{{ $u->email }}</td><td>{{ $u->role?->nom }}</td>
                        <td class="text-doux">{{ $u->derniere_connexion?->diffForHumans() ?? 'Jamais' }}</td>
                        <td><span class="etat {{ $u->actif ? 'etat-ok' : 'etat-neutre' }}">{{ $u->actif ? 'Actif' : 'Désactivé' }}</span></td>
                        <td class="text-end text-nowrap"><a href="{{ route('utilisateurs.edit', $u) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                            @if ($u->actif && $u->id !== auth()->id())
                                <form method="post" action="{{ route('utilisateurs.destroy', $u) }}" class="d-inline" data-confirmer="Désactiver ce compte ? La personne ne pourra plus se connecter.">@csrf @method('delete')
                                    <button class="btn btn-sm btn-light text-danger" title="Désactiver"><i class="bi bi-person-slash"></i></button></form>
                            @endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endsection
