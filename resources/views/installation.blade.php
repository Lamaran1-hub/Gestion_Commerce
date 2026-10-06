@extends('layouts.public')
@section('titre', 'Installation')
@section('contenu')
    <h2 class="h3 fw-bold mb-1">Bienvenue : création de votre espace Propriétaire</h2>
    <p class="text-doux mb-4">Cette étape n'a lieu qu'une seule fois. Le compte créé ici gère tous vos clients : licences, paiements,
        utilisateurs, annonces et assistance.</p>
    <form method="post" action="{{ route('installation') }}" class="row g-3">
        @csrf
        <div class="col-12"><h3 class="h6 mb-0"><i class="bi bi-building me-1"></i>Votre société (éditeur du logiciel)</h3>
            <div class="form-text">Ces coordonnées s'affichent chez vos clients pour renouveler leur licence ou vous contacter.</div></div>
        <div class="col-sm-6"><label class="form-label" for="societe">Nom de la société</label>
            <input name="societe" id="societe" value="{{ old('societe') }}" class="form-control" required placeholder="Ex. : Ma Société SARL"></div>
        <div class="col-sm-6"><label class="form-label" for="nom_logiciel">Nom de votre logiciel</label>
            <input name="nom_logiciel" id="nom_logiciel" value="{{ old('nom_logiciel') }}" class="form-control" required placeholder="Ex. : Caisse Plus"></div>
        <div class="col-sm-6"><label class="form-label" for="societe_telephone">Téléphone</label>
            <input name="societe_telephone" id="societe_telephone" value="{{ old('societe_telephone') }}" class="form-control" required placeholder="+224 6XX XX XX XX"></div>
        <div class="col-sm-6"><label class="form-label" for="societe_email">E-mail de contact</label>
            <input type="email" name="societe_email" id="societe_email" value="{{ old('societe_email') }}" class="form-control"></div>

        <div class="col-12 mt-4"><h3 class="h6 mb-0"><i class="bi bi-person-badge me-1"></i>Votre compte Propriétaire</h3></div>
        <div class="col-sm-6"><label class="form-label" for="prenom">Prénom</label>
            <input name="prenom" id="prenom" value="{{ old('prenom') }}" class="form-control" required></div>
        <div class="col-sm-6"><label class="form-label" for="nom">Nom</label>
            <input name="nom" id="nom" value="{{ old('nom') }}" class="form-control" required></div>
        <div class="col-12"><label class="form-label" for="email">E-mail de connexion</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control" required autocomplete="username"></div>
        <div class="col-sm-6"><label class="form-label" for="password">Mot de passe</label>
            <input type="password" name="password" id="password" class="form-control" required autocomplete="new-password">
            <div class="form-text">8 caractères minimum, lettres et chiffres.</div></div>
        <div class="col-sm-6"><label class="form-label" for="password_confirmation">Confirmation</label>
            <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password"></div>
        <div class="col-12"><button class="btn btn-primary btn-lg w-100">Créer mon espace Propriétaire</button></div>
    </form>
@endsection
