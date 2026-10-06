@extends('layouts.app')
@section('titre', $role->exists ? 'Modifier le rôle' : 'Nouveau rôle')
@section('contenu')
    <div class="entete-page"><h1>{{ $role->exists ? 'Rôle : '.$role->nom : 'Nouveau rôle' }}</h1></div>
    <form method="post" action="{{ $role->exists ? route('roles.update', $role) : route('roles.store') }}">
        @csrf @if ($role->exists) @method('put') @endif
        <div class="bloc bloc-corps mb-3" style="max-width:480px">
            <label class="form-label" for="nom">Nom du rôle</label>
            <input name="nom" id="nom" value="{{ old('nom', $role->nom) }}" class="form-control" required placeholder="Ex. : Caissier">
        </div>
        @php($actuelles = old('permissions', $role->permissions ?? []))
        <div class="row g-3">
            @foreach (config('gestion.permissions') as $groupe => $perms)
                <div class="col-md-6 col-xl-4">
                    <fieldset class="bloc h-100">
                        <legend class="bloc-entete h6 mb-0 w-100">{{ $groupe }}</legend>
                        <div class="bloc-corps">
                            @foreach ($perms as $cle => $lib)
                                <div class="form-check mb-2">
                                    <input type="checkbox" name="permissions[]" value="{{ $cle }}" id="p_{{ $cle }}" class="form-check-input" @checked(in_array($cle, $actuelles))>
                                    <label for="p_{{ $cle }}" class="form-check-label">{{ $lib }}</label>
                                </div>
                            @endforeach
                        </div>
                    </fieldset>
                </div>
            @endforeach
        </div>
        <div class="mt-3 d-flex gap-2"><button class="btn btn-primary">Enregistrer le rôle</button><a href="{{ route('roles.index') }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection
