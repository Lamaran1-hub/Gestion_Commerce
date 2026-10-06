@extends('layouts.public')
@section('titre', 'Nouveau mot de passe')
@section('contenu')
    <h2 class="h3 fw-bold mb-1">Choisissez un nouveau mot de passe</h2>
    <p class="text-doux mb-4">8 caractères minimum, avec des lettres et des chiffres. Vous serez déconnecté de tous vos appareils.</p>
    <form method="post" action="{{ route('password.update') }}" class="vstack gap-3">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <div><label class="form-label" for="email">Adresse e-mail</label>
            <input type="email" name="email" id="email" value="{{ old('email', $email) }}" class="form-control form-control-lg" required autocomplete="username"></div>
        <div><label class="form-label" for="password">Nouveau mot de passe</label>
            <input type="password" name="password" id="password" class="form-control form-control-lg" required autocomplete="new-password" autofocus></div>
        <div><label class="form-label" for="password_confirmation">Confirmation</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control form-control-lg" required autocomplete="new-password"></div>
        <button class="btn btn-primary btn-lg">Enregistrer le mot de passe</button>
    </form>
@endsection
