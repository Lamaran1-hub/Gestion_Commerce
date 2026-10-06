@extends('layouts.app')
@section('titre', 'Mon profil')
@section('contenu')
    <div class="entete-page"><h1>Mon profil</h1></div>
    <div class="row g-4">
        <div class="col-lg-6">
            <form method="post" action="{{ route('profil.update') }}" class="bloc">
                @csrf @method('put')
                <div class="bloc-entete"><h2 class="mb-0">Informations</h2></div>
                <div class="bloc-corps row g-3">
                    <div class="col-sm-6"><label class="form-label" for="prenom">Prénom</label>
                        <input name="prenom" id="prenom" value="{{ old('prenom', $user->prenom) }}" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label" for="nom">Nom</label>
                        <input name="nom" id="nom" value="{{ old('nom', $user->nom) }}" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label" for="email">E-mail</label>
                        <input type="email" name="email" id="email" value="{{ old('email', $user->email) }}" class="form-control" required></div>
                    <div class="col-sm-6"><label class="form-label" for="telephone">Téléphone</label>
                        <input name="telephone" id="telephone" value="{{ old('telephone', $user->telephone) }}" class="form-control"></div>
                    <div class="col-12"><div class="form-check form-switch">
                        <input type="hidden" name="recevoir_nouveautes" value="0">
                        <input type="checkbox" name="recevoir_nouveautes" value="1" id="recevoir_nouveautes" class="form-check-input" @checked(old('recevoir_nouveautes', $user->recevoir_nouveautes))>
                        <label for="recevoir_nouveautes" class="form-check-label">Recevoir les nouveautés et mises à jour du logiciel par e-mail</label></div>
                        <div class="form-text">Les e-mails importants (paiement, échéance de licence, sécurité du compte) sont toujours envoyés.</div></div>
                    <div class="col-12"><button class="btn btn-primary">Enregistrer</button></div>
                </div>
            </form>
        </div>
        <div class="col-lg-6">
            <form method="post" action="{{ route('profil.mot-de-passe') }}" class="bloc {{ $user->doit_changer_mot_de_passe ? 'border-primary' : '' }}" id="mot-de-passe">
                @csrf @method('put')
                <div class="bloc-entete"><h2 class="mb-0">Changer de mot de passe</h2></div>
                <div class="bloc-corps row g-3">
                    @if ($user->doit_changer_mot_de_passe)
                        <div class="col-12"><div class="alert alert-info small mb-0"><i class="bi bi-info-circle me-1"></i>
                            Dans « Mot de passe actuel », saisissez le <strong>mot de passe provisoire</strong> qui vous a été remis
                            (par l'administrateur de la boutique ou par l'éditeur). Choisissez ensuite un mot de passe personnel
                            d'au moins 8 caractères, avec des lettres et des chiffres.</div></div>
                    @endif
                    <div class="col-12"><label class="form-label" for="mot_de_passe_actuel">Mot de passe actuel</label>
                        <input type="password" name="mot_de_passe_actuel" id="mot_de_passe_actuel" class="form-control" required autocomplete="current-password"></div>
                    <div class="col-sm-6"><label class="form-label" for="password">Nouveau mot de passe</label>
                        <input type="password" name="password" id="password" class="form-control" required autocomplete="new-password"></div>
                    <div class="col-sm-6"><label class="form-label" for="password_confirmation">Confirmation</label>
                        <input type="password" name="password_confirmation" id="password_confirmation" class="form-control" required autocomplete="new-password"></div>
                    <div class="col-12"><button class="btn btn-primary">Changer le mot de passe</button></div>
                </div>
            </form>
        </div>
    </div>
@endsection
