@extends('layouts.public')
@section('titre', 'Connexion')
@section('contenu')
    @php
        $marqueAccueil = boutique_affichee();
        // Logo avant le formulaire : après une déconnexion (manuelle ou pour inactivité), ou à la première
        // ouverture de l'application dans le navigateur. Jamais au retour d'une erreur de saisie.
        $forcerAccueil = (bool) session('afficher_logo');
        $afficherAccueil = ! $errors->any() && ! session('erreur');
        $duree = max(1, (int) config('gestion.duree_accueil'));
    @endphp
    @if ($afficherAccueil)
        <div class="ecran-accueil" id="ecranAccueil" role="status" aria-live="polite" style="--duree: {{ $duree }}s" data-forcer="{{ $forcerAccueil ? 1 : 0 }}">
            <div class="ecran-accueil-centre">
                <img src="{{ $marqueAccueil?->logoUrl() ?? logo_plateforme() }}" alt="Logo {{ $marqueAccueil?->nom ?? config('app.name') }}" class="ecran-accueil-logo">
                <div class="ecran-accueil-nom">{{ $marqueAccueil?->nom ?? config('app.name') }}</div>
                @if ($marqueAccueil?->ville)<div class="ecran-accueil-sous">{{ $marqueAccueil->ville }}</div>@endif
                <div class="ecran-accueil-barre"><span></span></div>
                <div class="ecran-accueil-sous mt-3">Chargement de l'espace de connexion…</div>
            </div>
        </div>
        <script>
            // Déjà vu dans cette session du navigateur (simple navigation) : on passe directement au formulaire
            (() => {
                const e = document.getElementById('ecranAccueil');
                let vu = false;
                try { vu = sessionStorage.getItem('gn_logo_vu') === '1'; sessionStorage.setItem('gn_logo_vu', '1'); } catch {}
                if (vu && e.dataset.forcer !== '1') e.remove();
            })();
        </script>
    @endif
    @unless (\App\Support\Plateforme::estInstallee())
        <div class="alert alert-info d-flex gap-3 align-items-center">
            <i class="bi bi-rocket-takeoff fs-3"></i>
            <div><strong>Première utilisation ?</strong> Aucun compte Propriétaire n'existe encore.
                <a href="{{ route('installation') }}" class="alert-link">Créer l'espace Propriétaire</a></div>
        </div>
    @endunless
    <h2 class="h3 fw-bold mb-1">Connexion</h2>
    <p class="text-doux mb-4">Entrez l'adresse e-mail et le mot de passe fournis par votre boutique.</p>
    <form method="post" action="{{ route('login') }}" class="vstack gap-3">
        @csrf
        <div>
            <label class="form-label" for="email">Adresse e-mail</label>
            <input type="email" name="email" id="email" value="{{ old('email') }}" class="form-control form-control-lg" required autocomplete="username" @unless ($afficherAccueil) autofocus @endunless>
        </div>
        <div>
            <div class="d-flex justify-content-between"><label class="form-label" for="password">Mot de passe</label>
                <a href="{{ route('mot-de-passe.oublie') }}" class="small">Mot de passe oublié ?</a></div>
            <input type="password" name="password" id="password" class="form-control form-control-lg" required autocomplete="current-password">
        </div>
        <div class="form-text"><i class="bi bi-shield-lock me-1"></i>Par sécurité, vous serez déconnecté après {{ config('gestion.inactivite_minutes') }} minutes d'inactivité.</div>
        <button class="btn btn-primary btn-lg">Se connecter</button>
    </form>
    @if (config('gestion.inscription_ouverte'))
        <p class="mt-4 text-doux">Pas encore de compte ? <a href="{{ route('inscription') }}">Créer ma boutique</a></p>
    @endif
@endsection
@if ($afficherAccueil ?? false)
    @push('scripts')
    <script>
        // Le logo reste affiché {{ $duree }} secondes, puis le formulaire apparaît
        (() => {
            const ecran = document.getElementById('ecranAccueil');
            if (!ecran) { document.getElementById('email')?.focus(); return; }
            const fermer = () => {
                ecran.classList.add('fini');
                setTimeout(() => { ecran.remove(); document.getElementById('email')?.focus(); }, 400);
            };
            setTimeout(fermer, {{ $duree * 1000 }});
        })();
    </script>
    @endpush
@endif
@push('scripts')
<script>
    // Déconnecté : la page de caisse gardée sur l'appareil (données de l'utilisateur précédent) est effacée.
    // Les ventes hors connexion non envoyées, elles, restent sur l'appareil jusqu'à leur envoi.
    if ('caches' in window) caches.delete('gn-caisse-v1');
</script>
@endpush
