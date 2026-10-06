@extends('layouts.app')
@section('titre', $utilisateur->exists ? 'Modifier l\'utilisateur' : 'Nouvel utilisateur')
@section('contenu')
    <div class="entete-page"><h1>{{ $utilisateur->exists ? 'Modifier '.$utilisateur->nomComplet() : 'Nouvel utilisateur' }}</h1></div>
    <form method="post" action="{{ $utilisateur->exists ? route('utilisateurs.update', $utilisateur) : route('utilisateurs.store') }}" class="bloc bloc-corps row g-3" style="max-width:760px">
        @csrf @if ($utilisateur->exists) @method('put') @endif
        <div class="col-md-6"><label class="form-label" for="prenom">Prénom</label><input name="prenom" id="prenom" value="{{ old('prenom', $utilisateur->prenom) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label" for="nom">Nom</label><input name="nom" id="nom" value="{{ old('nom', $utilisateur->nom) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label" for="email">E-mail (identifiant de connexion)</label><input type="email" name="email" id="email" value="{{ old('email', $utilisateur->email) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label" for="telephone">Téléphone</label><input name="telephone" id="telephone" value="{{ old('telephone', $utilisateur->telephone) }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label" for="role_id">Rôle</label>
            @php($soiMeme = $utilisateur->exists && $utilisateur->id === auth()->id())
            {{-- Son propre compte : rôle et activation ne se modifient pas (on perdrait l'accès) --}}
            <select name="role_id" id="role_id" class="form-select" required @disabled($soiMeme)>
                @foreach ($roles as $r)<option value="{{ $r->id }}" @selected(old('role_id', $utilisateur->role_id) == $r->id)>{{ $r->nom }}</option>@endforeach</select>
            @if ($soiMeme)<input type="hidden" name="role_id" value="{{ $utilisateur->role_id }}">@endif
            <div class="form-text">@if ($soiMeme)C'est votre compte : un autre administrateur peut changer votre rôle. · @endif<a href="{{ route('roles.index') }}">Voir ce que chaque rôle peut faire</a></div></div>
        <div class="col-md-6 d-flex align-items-center"><div class="form-check form-switch mt-3">
            <input type="checkbox" name="actif" value="1" id="actif" class="form-check-input" @checked(old('actif', $utilisateur->actif)) @disabled($soiMeme)>
            @if ($soiMeme)<input type="hidden" name="actif" value="1">@endif
            <label for="actif" class="form-check-label">Compte actif{{ $soiMeme ? ' (votre compte)' : '' }}</label></div></div>
        @if (fonction('equipe'))
            <div class="col-12"><h2 class="h6 mt-2 mb-0">Objectif et commission</h2>
                <div class="form-text">Le vendeur suit sa progression à la caisse ; la commission est calculée sur son chiffre d'affaires hors taxes du mois.</div></div>
            <div class="col-md-6"><label class="form-label" for="objectif_mensuel">Objectif de ventes du mois (GNF)</label>
                <input name="objectif_mensuel" id="objectif_mensuel" data-montant inputmode="numeric" value="{{ old('objectif_mensuel', $utilisateur->objectif_mensuel) }}" class="form-control text-end" placeholder="Vide = pas d'objectif"></div>
            <div class="col-md-6"><label class="form-label" for="commission_pct">Commission (% des ventes HT)</label>
                <input type="number" step="0.1" min="0" max="50" name="commission_pct" id="commission_pct" value="{{ old('commission_pct', $utilisateur->commission_pct) }}" class="form-control" placeholder="Vide = pas de commission"></div>
        @endif
        <div class="col-12"><h2 class="h6 mt-2 mb-0">{{ $utilisateur->exists ? 'Réinitialiser le mot de passe (facultatif)' : 'Mot de passe provisoire' }}</h2>
            <div class="form-text">La personne devra le changer à sa première connexion.</div></div>
        <div class="col-md-6"><label class="form-label" for="password">Mot de passe</label>
            <input type="password" name="password" id="password" class="form-control" autocomplete="new-password" @required(! $utilisateur->exists)></div>
        <div class="col-md-6"><label class="form-label" for="password_confirmation">Confirmation</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" autocomplete="new-password" @required(! $utilisateur->exists)></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="{{ route('utilisateurs.index') }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection
