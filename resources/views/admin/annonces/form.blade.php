@extends('layouts.app')
@section('titre', $annonce->exists ? "Modifier l'annonce" : 'Nouvelle annonce')
@section('contenu')
    <div class="entete-page"><h1>{{ $annonce->exists ? "Modifier l'annonce" : 'Nouvelle annonce' }}</h1></div>
    <form method="post" action="{{ $annonce->exists ? route('admin.annonces.update', $annonce) : route('admin.annonces.store') }}" class="bloc bloc-corps row g-3" style="max-width:860px">
        @csrf @if ($annonce->exists) @method('put') @endif
        <div class="col-md-8"><label class="form-label" for="titre">Titre</label>
            <input name="titre" id="titre" value="{{ old('titre', $annonce->titre) }}" class="form-control" required placeholder="Ex. : Nouveau : export des rapports en PDF"></div>
        <div class="col-md-4"><label class="form-label" for="type">Type</label>
            <select name="type" id="type" class="form-select">
                @foreach (\App\Models\Annonce::TYPES as $k => [$l])<option value="{{ $k }}" @selected(old('type', $annonce->type) === $k)>{{ $l }}</option>@endforeach</select>
            <div class="form-text">« Important » et « Maintenance » s'affichent en haut de chaque page jusqu'à lecture.</div></div>
        <div class="col-12"><label class="form-label" for="contenu">Message</label>
            <textarea name="contenu" id="contenu" rows="7" class="form-control" required>{{ old('contenu', $annonce->contenu) }}</textarea></div>
        <div class="col-md-6"><label class="form-label" for="boutique_id">Destinataires</label>
            <select name="boutique_id" id="boutique_id" class="form-select"><option value="">Toutes les boutiques</option>
                @foreach ($boutiques as $b)<option value="{{ $b->id }}" @selected(old('boutique_id', $annonce->boutique_id ?? request('boutique_id')) == $b->id)>{{ $b->nom }} uniquement</option>@endforeach</select></div>
        <div class="col-md-6"><label class="form-label" for="expire_le">Afficher jusqu'au</label>
            <input type="date" name="expire_le" id="expire_le" value="{{ old('expire_le', $annonce->expire_le?->toDateString()) }}" min="{{ now()->toDateString() }}" class="form-control">
            <div class="form-text">Vide = toujours visible dans « Nouveautés ».</div></div>
        <div class="col-12"><div class="form-check form-switch">
            <input type="checkbox" name="publier" value="1" id="publier" class="form-check-input" @checked(old('publier', $annonce->exists ? (bool) $annonce->publiee_le : true))>
            <label for="publier" class="form-check-label">Publier maintenant (sinon : brouillon)</label></div></div>
        <div class="col-12">
            @if ($annonce->email_envoye_le)
                <div class="small text-doux"><i class="bi bi-envelope-check me-1"></i>Déjà envoyée par e-mail le {{ $annonce->email_envoye_le->format('d/m/Y à H:i') }}.</div>
            @else
                <div class="form-check form-switch">
                    <input type="checkbox" name="envoyer_email" value="1" id="envoyer_email" class="form-check-input" @checked(old('envoyer_email'))>
                    <label for="envoyer_email" class="form-check-label">Envoyer aussi par e-mail aux administrateurs des boutiques concernées</label></div>
                <div class="form-text">Une seule fois, à la publication. Les personnes désabonnées des nouveautés ne la reçoivent pas.</div>
            @endif
        </div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary">Enregistrer</button><a href="{{ route('admin.annonces.index') }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection
