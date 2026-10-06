@extends('layouts.app')
@section('titre', 'Rôles et droits')
@section('contenu')
    <div class="entete-page"><div><h1>Rôles et droits</h1><div class="text-doux">Un rôle définit ce qu'un utilisateur peut voir et faire dans la boutique.</div></div>
        <a href="{{ route('roles.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Nouveau rôle</a></div>
    <div class="row g-3">
        @foreach ($roles as $r)
            <div class="col-md-6 col-xl-4">
                <div class="bloc h-100 d-flex flex-column">
                    <div class="bloc-entete"><h2 class="mb-0">{{ $r->nom }}</h2><span class="small text-doux">{{ $r->utilisateurs_count }} utilisateur(s)</span></div>
                    <div class="bloc-corps flex-grow-1">
                        @if ($r->systeme)
                            <p class="mb-0">Tous les droits, y compris la gestion des utilisateurs et des paramètres. Ce rôle ne peut pas être modifié.</p>
                        @else
                            <ul class="small mb-0 ps-3">
                                @foreach (config('gestion.permissions') as $groupe => $perms)
                                    @foreach ($perms as $cle => $lib)@if (in_array($cle, $r->permissions ?? []))<li>{{ $lib }}</li>@endif @endforeach
                                @endforeach
                            </ul>
                        @endif
                    </div>
                    @unless ($r->systeme)
                        <div class="bloc-corps border-top d-flex gap-2">
                            <a href="{{ route('roles.edit', $r) }}" class="btn btn-sm btn-outline-primary">Modifier</a>
                            @if (! $r->utilisateurs_count)
                                <form method="post" action="{{ route('roles.destroy', $r) }}" data-confirmer="Supprimer ce rôle ?">@csrf @method('delete')<button class="btn btn-sm btn-link text-danger">Supprimer</button></form>
                            @endif
                        </div>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>
@endsection
