@php($marque = boutique_affichee())
<!doctype html>
<html lang="fr">
<head>
    @include('layouts.tete')
    <title>@yield('titre') · {{ $marque?->nom ?? config('app.name') }}</title>
</head>
<body>
<div class="public">
    <section class="public-visuel">
        <div class="fw-bold fs-5 d-flex align-items-center gap-2">
            <img src="{{ $marque?->logoUrl() ?? logo_plateforme() }}" alt="" class="public-logo">{{ $marque?->nom ?? config('app.name') }}
        </div>
        <div>
            <h1 class="display-6 fw-bold mb-3" style="max-width: 18ch">Votre boutique, vos ventes, votre stock. Au franc près.</h1>
            <p class="mb-4" style="max-width: 44ch; color: #BFD1C8">Encaissez en espèces, Orange Money ou MTN, suivez les crédits de vos clients
                et sachez chaque soir ce qui reste en rayon.</p>
            <div class="recu-apercu">
                <div class="fw-bold">Quincaillerie Camara</div>
                <div class="text-doux small">Kindia · V-2026-00148</div>
                <div class="pointille"></div>
                <div class="d-flex justify-content-between"><span>Ciment 50 kg × 4</span><span>360 000</span></div>
                <div class="d-flex justify-content-between"><span>Tôle ondulée × 10</span><span>650 000</span></div>
                <div class="pointille"></div>
                <div class="d-flex justify-content-between fw-bold"><span>Total</span><span>1 010 000 GNF</span></div>
                <div class="d-flex justify-content-between text-doux"><span>Orange Money</span><span>1 010 000</span></div>
            </div>
        </div>
        <small style="color: #8FA59B">© {{ date('Y') }} {{ \App\Support\Plateforme::get('societe', config('app.name')) }}{{ \App\Support\Plateforme::get('telephone') ? ' · '.\App\Support\Plateforme::get('telephone') : '' }}</small>
    </section>
    <section class="public-form">
        <div>
            @include('partials.flash')
            @yield('contenu')
        </div>
    </section>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}?v=6"></script>
@stack('scripts')
</body>
</html>
