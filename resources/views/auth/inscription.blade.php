@extends('layouts.public')
@section('titre', 'Créer ma boutique')
@section('contenu')
    <h2 class="h3 fw-bold mb-1">Créer ma boutique</h2>
    <p class="text-doux mb-4">{{ config('gestion.jours_essai') }} jours d'essai gratuit, sans engagement. Vous ajouterez votre logo à l'étape suivante.</p>
    <form method="post" action="{{ route('inscription') }}" class="row g-3">
        @csrf
        {!! \App\Support\AntiRobot::champs() !!}
        @error('formulaire')<div class="col-12"><div class="alert alert-danger py-2 mb-0" role="alert">{{ $message }}</div></div>@enderror
        <div class="col-12"><h3 class="h6 mb-0">La boutique</h3></div>
        <div class="col-12">
            <label class="form-label" for="boutique_nom">Nom de la boutique</label>
            <input name="boutique_nom" id="boutique_nom" value="{{ old('boutique_nom') }}" class="form-control" required placeholder="Ex. : Alimentation Bah & Fils">
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="boutique_telephone">Téléphone</label>
            <input name="boutique_telephone" id="boutique_telephone" value="{{ old('boutique_telephone') }}" class="form-control" required placeholder="+224 6XX XX XX XX">
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="ville">Ville</label>
            <input name="ville" id="ville" value="{{ old('ville') }}" class="form-control" placeholder="Conakry, Kindia...">
        </div>
        <div class="col-12 mt-4"><h3 class="h6 mb-0">Votre compte administrateur</h3></div>
        <div class="col-sm-6">
            <label class="form-label" for="prenom">Prénom</label>
            <input name="prenom" id="prenom" value="{{ old('prenom') }}" class="form-control" required>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="nom">Nom</label>
            <input name="nom" id="nom" value="{{ old('nom') }}" class="form-control" required>
        </div>
        <div class="col-12">
            <label class="form-label" for="email">Adresse e-mail</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control" required autocomplete="username">
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="password">Mot de passe</label>
            <input type="password" name="password" id="password" class="form-control" required autocomplete="new-password">
            <div class="form-text">8 caractères minimum, avec lettres et chiffres.</div>
        </div>
        <div class="col-sm-6">
            <label class="form-label" for="password_confirmation">Confirmation</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password">
        </div>
        <div class="col-12 d-grid mt-3"><button class="btn btn-primary btn-lg">Créer ma boutique</button></div>
    </form>
    <p class="mt-4 text-doux">Déjà inscrit ? <a href="{{ route('login') }}">Se connecter</a></p>
@endsection
