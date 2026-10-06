@extends('layouts.public')
@section('titre', 'Désabonnement')
@section('contenu')
    <div class="text-center py-4">
        <i class="bi bi-envelope-slash fs-1 text-doux"></i>
        <h1 class="h4 mt-2">Vous êtes désabonné(e) des nouveautés</h1>
        <p class="text-doux mb-1">{{ $user->email }} ne recevra plus les e-mails de nouveautés et de mises à jour.</p>
        <p class="small text-doux">Les e-mails importants (paiement, échéance de licence, sécurité du compte) restent envoyés.
            Vous pouvez vous réabonner à tout moment depuis votre profil.</p>
        <a href="{{ route('login') }}" class="btn btn-primary mt-2">Ouvrir le logiciel</a>
    </div>
@endsection
