@extends('layouts.app')
@section('titre', 'Nouveau client')
@section('contenu')
    <div class="entete-page"><div><h1>Nouveau client</h1>
        <div class="text-doux">Enregistrez l'entreprise qui achète le logiciel et son premier utilisateur. Un mot de passe provisoire est généré.</div></div></div>
    <form method="post" action="{{ route('admin.boutiques.store') }}" class="row g-4">
        @csrf
        <div class="col-xl-7"><div class="bloc bloc-corps row g-3">@include('admin.boutiques._fiche')</div></div>
        <div class="col-xl-5">
            <div class="bloc bloc-corps row g-3">
                <div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-patch-check me-1"></i>Licence</h2></div>
                <div class="col-12"><label class="form-label" for="plan_id">Formule</label><select name="plan_id" id="plan_id" class="form-select">
                    @foreach ($plans as $p)<option value="{{ $p->id }}" @selected(old('plan_id') == $p->id)>{{ $p->nom }} — {{ gnf($p->prix_mensuel) }}/mois</option>@endforeach</select></div>
                <div class="col-6"><label class="form-label" for="statut">État</label><select name="statut" id="statut" class="form-select">
                    <option value="essai" @selected(old('statut', 'essai') === 'essai')>Période d'essai</option>
                    <option value="actif" @selected(old('statut') === 'actif')>Licence payée</option></select></div>
                <div class="col-6"><label class="form-label" for="abonnement_expire_le">Accès jusqu'au</label>
                    <input type="date" name="abonnement_expire_le" id="abonnement_expire_le" value="{{ old('abonnement_expire_le', now()->addDays(config('gestion.jours_essai'))->toDateString()) }}" class="form-control" required></div>
                <div class="col-12 form-text mt-0">Si le client a déjà payé, préférez « Période d'essai » ici puis <strong>Enregistrer un paiement</strong> sur sa fiche : le paiement sera tracé et un reçu généré.</div>
            </div>
            <div class="bloc bloc-corps row g-3 mt-3">
                <div class="col-12"><h2 class="h6 mb-0"><i class="bi bi-person-badge me-1"></i>Premier utilisateur (administrateur de la boutique)</h2></div>
                <div class="col-6"><label class="form-label" for="admin_prenom">Prénom</label><input name="admin_prenom" id="admin_prenom" value="{{ old('admin_prenom') }}" class="form-control" required></div>
                <div class="col-6"><label class="form-label" for="admin_nom">Nom</label><input name="admin_nom" id="admin_nom" value="{{ old('admin_nom') }}" class="form-control" required></div>
                <div class="col-7"><label class="form-label" for="admin_email">E-mail de connexion</label><input type="email" name="admin_email" id="admin_email" value="{{ old('admin_email') }}" class="form-control" required></div>
                <div class="col-5"><label class="form-label" for="admin_telephone">Téléphone</label><input name="admin_telephone" id="admin_telephone" value="{{ old('admin_telephone') }}" class="form-control"></div>
            </div>
        </div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary btn-lg">Enregistrer le client</button><a href="{{ route('admin.boutiques.index') }}" class="btn btn-light btn-lg">Annuler</a></div>
    </form>
@endsection
