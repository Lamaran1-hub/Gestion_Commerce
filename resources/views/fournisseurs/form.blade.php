@extends('layouts.app')
@section('titre', $fournisseur->exists ? 'Modifier le fournisseur' : 'Nouveau fournisseur')
@section('contenu')
    <div class="entete-page"><h1>{{ $fournisseur->exists ? 'Modifier '.$fournisseur->nom : 'Nouveau fournisseur' }}</h1></div>
    <form method="post" action="{{ $fournisseur->exists ? route('fournisseurs.update', $fournisseur) : route('fournisseurs.store') }}" class="bloc bloc-corps row g-3" style="max-width:760px">
        @csrf @if ($fournisseur->exists) @method('put') @endif
        <div class="col-md-6"><label class="form-label" for="nom">Nom / Société</label><input name="nom" id="nom" value="{{ old('nom', $fournisseur->nom) }}" class="form-control" required></div>
        <div class="col-md-6"><label class="form-label" for="contact">Personne à contacter</label><input name="contact" id="contact" value="{{ old('contact', $fournisseur->contact) }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label" for="telephone">Téléphone</label><input name="telephone" id="telephone" value="{{ old('telephone', $fournisseur->telephone) }}" class="form-control"></div>
        <div class="col-md-6"><label class="form-label" for="email">E-mail</label><input type="email" name="email" id="email" value="{{ old('email', $fournisseur->email) }}" class="form-control"></div>
        <div class="col-12"><label class="form-label" for="adresse">Adresse</label><input name="adresse" id="adresse" value="{{ old('adresse', $fournisseur->adresse) }}" class="form-control"></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="{{ route('fournisseurs.index') }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection
