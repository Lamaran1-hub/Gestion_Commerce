@extends('layouts.public')
@section('titre', 'Bienvenue')
@section('contenu')
    <h2 class="h3 fw-bold mb-2">Gérez votre commerce simplement</h2>
    <p class="text-doux mb-4">Ventes, stock, clients, crédits et dépenses de votre boutique, sur ordinateur comme sur téléphone.</p>
    <ul class="list-unstyled mb-4">
        <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Caisse rapide avec lecteur de code-barres</li>
        <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Factures et reçus à votre logo</li>
        <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Plusieurs vendeurs, chacun avec ses droits</li>
        <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Exports Excel et PDF pour votre comptable</li>
    </ul>
    <div class="d-grid gap-2">
        @auth
            <a href="{{ auth()->user()->est_super_admin ? route('admin.dashboard') : route('dashboard') }}" class="btn btn-primary btn-lg">Ouvrir mon espace</a>
        @else
            @if (config('gestion.inscription_ouverte'))
                <a href="{{ route('inscription') }}" class="btn btn-primary btn-lg">Créer ma boutique — {{ config('gestion.jours_essai') }} jours gratuits</a>
            @endif
            <a href="{{ route('login') }}" class="btn btn-outline-primary btn-lg">Se connecter</a>
        @endauth
    </div>
@endsection
