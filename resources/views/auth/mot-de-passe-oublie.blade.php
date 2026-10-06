@extends('layouts.public')
@section('titre', 'Mot de passe oublié')
@section('contenu')
    @php($ed = \App\Support\Plateforme::tout())
    <h2 class="h3 fw-bold mb-1">Mot de passe oublié ?</h2>
    <p class="text-doux mb-4">Indiquez l'adresse e-mail de votre compte : nous vous envoyons un lien pour choisir un nouveau mot de passe.</p>
    <form method="post" action="{{ route('password.email') }}" class="vstack gap-3">
        @csrf
        <div>
            <label class="form-label" for="email">Adresse e-mail</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control form-control-lg" required autofocus autocomplete="username">
        </div>
        <button class="btn btn-primary btn-lg">Recevoir le lien</button>
    </form>

    <details class="mt-4">
        <summary class="text-doux">Vous n'avez pas accès à cette boîte e-mail ?</summary>
        <div class="vstack gap-2 mt-3 small">
            <div><strong>Vendeur, magasinier…</strong> : l'administrateur de votre boutique peut vous donner un nouveau mot de passe
                (menu <em>Utilisateurs</em> → modifier votre compte).</div>
            <div><strong>Administrateur de la boutique</strong> : contactez {{ $ed['societe'] ?? 'l\'éditeur du logiciel' }},
                qui vous enverra un mot de passe provisoire.
                @if (! empty($ed['telephone']))<br><i class="bi bi-telephone me-1"></i><a href="tel:{{ $ed['telephone'] }}">{{ $ed['telephone'] }}</a>@endif
                @if (! empty($ed['whatsapp']) && ($wa = lien_whatsapp($ed['whatsapp'], "Bonjour, j'ai oublié mon mot de passe pour le logiciel ".config('app.name').'. Ma boutique : ')))
                    <br><a href="{{ $wa }}" target="_blank" rel="noopener" class="btn btn-sm btn-success mt-1"><i class="bi bi-whatsapp me-1"></i>Écrire sur WhatsApp</a>
                @endif
            </div>
        </div>
    </details>
    <p class="mt-4"><a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1"></i>Retour à la connexion</a></p>
@endsection
