@extends('layouts.app')
@section('titre', $libelle)
@section('contenu')
    <div class="bloc bloc-corps text-center py-5" style="max-width:640px;margin:0 auto">
        <i class="bi bi-{{ config("gestion.fonctions.{$fonction}.1", 'lock') }} fs-1 text-doux"></i>
        <h1 class="h4 mt-2">{{ $libelle }}</h1>
        <p class="text-doux">Cette fonction n'est pas incluse dans votre formule <strong>{{ boutique()->plan?->nom ?? '' }}</strong>.</p>
        @if ($formules->isNotEmpty())
            <p>Elle est disponible avec : {{ $formules->map(fn ($p) => $p->nom.' ('.gnf($p->prix_mensuel).'/mois)')->implode(', ') }}.</p>
        @endif
        <div class="d-flex justify-content-center gap-2 mt-3">
            @can('parametres.gerer')<a href="{{ route('abonnement') }}" class="btn btn-primary"><i class="bi bi-arrow-up-circle me-1"></i>Voir les formules</a>@endcan
            <a href="{{ route('assistance.create', ['categorie' => 'licence']) }}" class="btn btn-outline-primary">Contacter l'éditeur</a>
        </div>
        <p class="small text-doux mt-3 mb-0">Vos données déjà saisies sont conservées.</p>
    </div>
@endsection
