@extends('layouts.app')
@section('titre', $demande->sujet)
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $demande->sujet }}</h1><div class="text-doux">{{ $demande->libelleCategorie() }} · <span class="etat {{ $demande->classeStatut() }}">{{ $demande->libelleStatut() }}</span></div></div>
        <a href="{{ route('assistance.index') }}" class="btn btn-light">Toutes mes demandes</a>
    </div>
    <div class="bloc bloc-corps" style="max-width:900px">
        @include('partials.conversation', ['cote' => 'boutique'])
        <form method="post" action="{{ route('assistance.repondre', $demande) }}" class="mt-4">
            @csrf
            <label class="form-label" for="contenu">{{ $demande->statut === 'fermee' ? 'Rouvrir la demande avec un nouveau message' : 'Ajouter un message' }}</label>
            <textarea name="contenu" id="contenu" rows="3" class="form-control" required></textarea>
            <button class="btn btn-primary mt-2"><i class="bi bi-send me-1"></i>Envoyer</button>
        </form>
    </div>
@endsection
