@extends('layouts.app')
@section('titre', 'Nouvelle demande')
@section('contenu')
    <div class="entete-page"><h1>Nouvelle demande d'assistance</h1></div>
    <form method="post" action="{{ route('assistance.store') }}" class="bloc bloc-corps row g-3" style="max-width:800px">
        @csrf
        <div class="col-md-8"><label class="form-label" for="sujet">Sujet</label>
            <input name="sujet" id="sujet" value="{{ old('sujet') }}" class="form-control" required placeholder="Ex. : Impossible d'imprimer le reçu"></div>
        <div class="col-md-4"><label class="form-label" for="categorie">Catégorie</label>
            <select name="categorie" id="categorie" class="form-select">
                @foreach (\App\Models\Demande::CATEGORIES as $k => $l)<option value="{{ $k }}" @selected(old('categorie', $categorie) === $k)>{{ $l }}</option>@endforeach</select></div>
        <div class="col-12"><label class="form-label" for="contenu">Votre message</label>
            <textarea name="contenu" id="contenu" rows="6" class="form-control" required placeholder="Décrivez ce que vous faisiez, ce qui s'est passé et ce que vous attendiez.">{{ old('contenu') }}</textarea></div>
        <div class="col-12 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-send me-1"></i>Envoyer</button>
            <a href="{{ route('assistance.index') }}" class="btn btn-light">Annuler</a></div>
    </form>
@endsection
