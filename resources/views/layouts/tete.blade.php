@php
    // Identité affichée : boutique connectée, ou dernière boutique utilisée sur cet appareil
    $marque ??= boutique_affichee();
    $logoOnglet = $marque?->logoUrl() ?? logo_plateforme();
@endphp
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="theme-color" content="{{ $marque?->couleur ?? '#1F6F54' }}">
{{-- Interface conçue en clair, aux couleurs de la boutique : pas d'assombrissement forcé par le navigateur (lisibilité, contrastes) --}}
<meta name="color-scheme" content="only light">
{{-- Extensions d'assombrissement (Dark Reader) : elles effaceraient les couleurs d'état (payée, crédit, annulée) --}}
<meta name="darkreader-lock">
{{-- Le logo de l'entreprise s'affiche dans l'onglet du navigateur --}}
<link rel="icon" href="{{ $logoOnglet }}">
<link rel="apple-touch-icon" href="{{ $logoOnglet }}">
{{-- Tout est hébergé sur le serveur : pas de dépendance à un CDN externe --}}
<link href="{{ asset('vendor/public-sans/public-sans.css') }}" rel="stylesheet">
<link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
<link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
<link href="{{ asset('css/app.css') }}?v=13" rel="stylesheet">
@if ($marque)<style>:root { {{ $marque->variablesCss() }} }</style>@endif
