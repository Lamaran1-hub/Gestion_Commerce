@extends('layouts.app')
@section('titre', $demande->sujet)
@section('contenu')
    <div class="entete-page">
        <div><h1>{{ $demande->sujet }}</h1>
            <div class="text-doux"><a href="{{ route('admin.boutiques.show', $demande->boutique) }}">{{ $demande->boutique->nom }}</a> · {{ $demande->libelleCategorie() }}
                · <span class="etat {{ $demande->classeStatut() }}">{{ $demande->libelleStatut() }}</span></div></div>
        <div class="d-flex gap-2">
            <a href="{{ route('admin.demandes.index') }}" class="btn btn-light">Retour</a>
            @if ($demande->statut !== 'fermee')
                <form method="post" action="{{ route('admin.demandes.cloturer', $demande) }}" data-confirmer="Clôturer cette demande ? Le client pourra la rouvrir en répondant."
                      data-confirmer-titre="Clôturer la demande" data-confirmer-bouton="Oui, clôturer">@csrf
                    <button class="btn btn-outline-secondary"><i class="bi bi-check2-all me-1"></i>Clôturer</button></form>
            @endif
        </div>
    </div>
    <div class="bloc bloc-corps" style="max-width:900px">
        @include('partials.conversation', ['cote' => 'proprietaire'])
        <form method="post" action="{{ route('admin.demandes.repondre', $demande) }}" class="mt-4">
            @csrf
            <label class="form-label" for="contenu">Votre réponse</label>
            <textarea name="contenu" id="contenu" rows="4" class="form-control" required></textarea>
            <button class="btn btn-primary mt-2"><i class="bi bi-send me-1"></i>Envoyer la réponse</button>
        </form>
    </div>
@endsection
