@php
    $user = auth()->user();
    $b = boutique() ?? $user->boutique;
    $estAdmin = $user->est_super_admin;
    $nbAlertes = (! $estAdmin && $user->aPermission('produits.voir'))
        ? \App\Models\Produit::where('actif', true)->enAlerte()->count() : 0;
    $nbCommandesEnLigne = (! $estAdmin && $b && $user->aPermission('ventes.voir')) ? \App\Models\Devis::commandesATraiter()->count() : 0;
    $nbLivraisons = (! $estAdmin && $b && $user->aPermission('ventes.voir')) ? \App\Models\Vente::aLivrer()->count() : 0;
    // Notifications : annonces non lues et réponses d'assistance (boutique) ; demandes à traiter (propriétaire)
    $nbAnnonces = $estAdmin ? 0 : \App\Models\Annonce::pourUtilisateur($user)->nonLuesPar($user)->count();
    $nbReponses = $estAdmin ? 0 : \App\Models\Demande::where('boutique_id', $user->boutique_id)->where('lue_boutique', false)->count();
    $nbDemandes = $estAdmin ? \App\Models\Demande::where('lue_proprietaire', false)->where('statut', '!=', 'fermee')->count() : 0;
    $nbEcheances = $estAdmin ? \App\Models\Boutique::where('statut', '!=', 'suspendu')->whereNotNull('abonnement_expire_le')
        ->whereDate('abonnement_expire_le', '<=', now()->addDays(7)->toDateString())->count() : 0;
    // Notifications système (paiement reçu, licence activée…) et paiements en ligne à traiter
    $nbNotifs = $user->unreadNotifications()->count();
    $nbCommandes = $estAdmin ? \App\Models\CommandeLicence::whereIn('statut', ['a_valider', 'anomalie'])->count() : 0;
    // Réseau de boutiques : l'administrateur passe d'un point de vente à l'autre
    $mesBoutiques = $estAdmin ? collect() : $user->boutiquesAccessibles();
    $nbTransfertsAttente = (! $estAdmin && $b?->entreprise_id && $user->aPermission('approvisionnements.gerer'))
        ? \App\Models\Transfert::where('boutique_destination_id', $b->id)->where('statut', 'envoye')->count() : 0;
    $lien = fn (string $route, string $icone, string $texte, ?string $motif = null, $pastille = null) =>
        '<a class="lien '.(request()->routeIs($motif ?? $route) ? 'actif' : '').'" href="'.route($route).'"><i class="bi bi-'.$icone.'"></i>'.e($texte)
        .($pastille ? '<span class="pastille">'.$pastille.'</span>' : '').'</a>';
@endphp
<!doctype html>
<html lang="fr">
<head>
    @include('layouts.tete')
    <title>@yield('titre', 'Accueil') · {{ $b?->nom ?? config('app.name') }}</title>
    @stack('styles')
</head>
<body data-inactivite="{{ config('gestion.inactivite_minutes') }}"
      @if (! $estAdmin && $user->aPermission('ventes.creer')) data-utilisateur="{{ $user->id }}" data-boutique="{{ $b->id }}"
      data-url-sync="{{ route('ventes.synchroniser') }}" data-url-ping="{{ route('ventes.ping') }}" data-url-sw="{{ asset('sw.js') }}" data-hors-ligne="{{ fonction('hors_ligne') ? 1 : 0 }}" @endif>
<div class="coque">
    <aside class="barre" aria-label="Menu principal">
        <div class="barre-entete">
            @if ($b?->logoUrl())
                <img src="{{ $b->logoUrl() }}" alt="" class="barre-logo">
            @elseif ($b)
                <div class="barre-initiales">{{ $b->initiales() }}</div>
            @else
                <img src="{{ logo_plateforme() }}" alt="" class="barre-logo">
            @endif
            <div>
                <div class="barre-nom">{{ $b?->nom ?? config('app.name') }}</div>
                <div class="barre-sous">{{ $estAdmin ? 'Espace propriétaire' : ($b->ville ?: 'Gestion commerciale') }}</div>
            </div>
        </div>
        @if ($mesBoutiques->count() > 1)
            <div class="dropdown px-3 pb-2">
                <button class="btn btn-sm btn-outline-light w-100 dropdown-toggle text-start" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-shop me-1"></i>Changer de boutique</button>
                <div class="dropdown-menu w-100">
                    @foreach ($mesBoutiques as $mb)
                        @if ($mb->id === $b->id)
                            <span class="dropdown-item active"><i class="bi bi-check2 me-1"></i>{{ $mb->nom }}</span>
                        @else
                            <form method="post" action="{{ route('reseau.activer', $mb) }}" data-sans-confirmation>@csrf
                                <button class="dropdown-item">{{ $mb->nom }}</button></form>
                        @endif
                    @endforeach
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="{{ route('reseau.index') }}"><i class="bi bi-grid me-1"></i>Vue d'ensemble</a>
                </div>
            </div>
        @endif
        <nav>
            @if ($estAdmin)
                <a href="{{ route('admin.boutiques.create') }}" class="btn btn-primary caisse d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-plus-lg"></i> Nouveau client
                </a>
                {!! $lien('admin.dashboard', 'speedometer2', 'Vue d\'ensemble') !!}
                <div class="groupe">Clients et licences</div>
                {!! $lien('admin.boutiques.index', 'shop', 'Clients (boutiques)', 'admin.boutiques.*', $nbEcheances ?: null) !!}
                {!! $lien('admin.commandes.index', 'phone', 'Paiements en ligne', 'admin.commandes.*', ($nbCommandes + $nbNotifs) ?: null) !!}
                {!! $lien('admin.paiements.index', 'cash-coin', 'Paiements de licences', 'admin.paiements.*') !!}
                {!! $lien('admin.plans.index', 'tags', 'Formules', 'admin.plans.*') !!}
                {!! $lien('admin.utilisateurs.index', 'people', 'Utilisateurs', 'admin.utilisateurs.*') !!}
                <div class="groupe">Communication</div>
                {!! $lien('admin.annonces.index', 'megaphone', 'Annonces et nouveautés', 'admin.annonces.*') !!}
                {!! $lien('admin.demandes.index', 'chat-dots', 'Demandes d\'assistance', 'admin.demandes.*', $nbDemandes ?: null) !!}
                <div class="groupe">Réglages</div>
                {!! $lien('admin.parametres.edit', 'gear', 'Ma société (éditeur)', 'admin.parametres.*') !!}
                {!! $lien('admin.emails.index', 'envelope-paper', 'E-mails', 'admin.emails.*') !!}
                {!! $lien('admin.sauvegardes.index', 'database-check', 'Sauvegardes', 'admin.sauvegardes.*') !!}
            @else
                @can('ventes.creer')
                    <a href="{{ route('ventes.create') }}" class="btn btn-primary caisse d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-cart-plus"></i> Nouvelle vente
                    </a>
                @endcan
                @can('dashboard.voir') {!! $lien('dashboard', 'grid-1x2', 'Tableau de bord') !!} @endcan

                @canany(['ventes.voir', 'clients.voir'])
                    <div class="groupe">Ventes</div>
                    @can('ventes.voir')
                        {!! $lien('ventes.index', 'receipt', 'Historique des ventes', 'ventes.index') !!}
                        {!! $lien('credits.index', 'hourglass-split', 'Crédits clients', 'credits.*') !!}
                        {!! $lien('devis.index', 'file-earmark-text', 'Devis / proforma', 'devis.*', $nbCommandesEnLigne ?: null) !!}
                        {!! $lien('livraisons.index', 'truck', 'Livraisons', 'livraisons.*', $nbLivraisons ?: null) !!}
                        {!! $lien('garanties.index', 'shield-check', 'Garanties', 'garanties.*') !!}
                        {!! $lien('cartes-cadeaux.index', 'gift', 'Cartes cadeaux', 'cartes-cadeaux.*') !!}
                        {!! $lien('clotures.index', 'lock', 'Clôtures de caisse', 'clotures.*') !!}
                    @endcan
                    @can('clients.voir') {!! $lien('clients.index', 'people', 'Clients', 'clients.*') !!} @endcan
                @endcanany

                @can('produits.voir')
                    <div class="groupe">Stock</div>
                    {!! $lien('produits.index', 'box-seam', 'Produits', 'produits.*', $nbAlertes ?: null) !!}
                    @if (fonction('promotions') && $user->aPermission('produits.gerer'))
                        @php($nbPromos = \App\Models\Promotion::enCours()->count())
                        {!! $lien('promotions.index', 'percent', 'Promotions', 'promotions.*', $nbPromos ?: null) !!}
                    @endif
                    @can('approvisionnements.gerer')
                        {!! $lien('stock.a-commander', 'cart-check', 'À commander', 'stock.a-commander') !!}
                        {!! $lien('commandes-fournisseur.index', 'clipboard-check', 'Commandes fournisseurs', 'commandes-fournisseur.*') !!}
                        {!! $lien('approvisionnements.index', 'truck', 'Approvisionnements', 'approvisionnements.*') !!}
                        @if ($b?->entreprise_id)
                            {!! $lien('transferts.index', 'arrow-left-right', 'Transferts', 'transferts.*', $nbTransfertsAttente ?: null) !!}
                        @endif
                        @php($nbDettesRetard = \App\Models\Approvisionnement::avecReste()->whereNotNull('echeance')->whereDate('echeance', '<', now()->toDateString())->count())
                        {!! $lien('fournisseurs.dettes', 'journal-minus', 'Dettes fournisseurs', 'fournisseurs.dettes', $nbDettesRetard ?: null) !!}
                    @endcan
                    @if (fonction('peremptions'))
                        @php($nbPerimes = app(\App\Services\Peremption::class)->lots(0)->count())
                        {!! $lien('stock.peremptions', 'calendar-x', 'Péremptions', 'stock.peremptions', $nbPerimes ?: null) !!}
                    @endif
                    {!! $lien('stock.mouvements', 'arrow-left-right', 'Mouvements de stock') !!}
                    @can('stock.ajuster') {!! $lien('stock.inventaire', 'clipboard-check', 'Inventaire') !!} @endcan
                    @can('fournisseurs.gerer') {!! $lien('fournisseurs.index', 'building', 'Fournisseurs', 'fournisseurs.*') !!} @endcan
                @endcan

                @canany(['depenses.gerer', 'rapports.voir'])
                    <div class="groupe">Finances</div>
                    @can('depenses.gerer') {!! $lien('depenses.index', 'wallet2', 'Dépenses', 'depenses.*') !!} @endcan
                    @if (fonction('tresorerie') && $user->aPermission('tresorerie.gerer')) {!! $lien('tresorerie.index', 'bank', 'Trésorerie', 'tresorerie.*') !!} @endif
                    @can('rapports.voir') {!! $lien('rapports.index', 'file-earmark-bar-graph', 'Rapports', 'rapports.*') !!} @endcan
                @endcanany

                @canany(['utilisateurs.gerer', 'parametres.gerer'])
                    <div class="groupe">Boutique</div>
                    @if (fonction('equipe')) {!! $lien('equipe.index', 'person-check', 'Équipe et présences', 'equipe.*') !!} @endif
                    @if (fonction('equipe') && $user->aPermission('utilisateurs.gerer')) {!! $lien('commissions.index', 'cash-stack', 'Commissions', 'commissions.*') !!} @endif
                    @if ($user->role?->systeme)
                        {!! $lien('reseau.index', 'shop-window', $mesBoutiques->count() > 1 ? 'Mes boutiques' : 'Ajouter une boutique', 'reseau.*') !!}
                    @endif
                    @can('utilisateurs.gerer')
                        {!! $lien('utilisateurs.index', 'person-badge', 'Utilisateurs', 'utilisateurs.*') !!}
                        {!! $lien('roles.index', 'shield-lock', 'Rôles et droits', 'roles.*') !!}
                    @endcan
                    @can('parametres.gerer') {!! $lien('parametres.edit', 'gear', 'Paramètres', 'parametres.*') !!} @endcan
                @endcanany

                <div class="groupe">Aide</div>
                {!! $lien('aide.index', 'question-circle', "Centre d'aide", 'aide.*') !!}
                {!! $lien('nouveautes.index', 'megaphone', 'Nouveautés', 'nouveautes.*', $nbAnnonces ?: null) !!}
                {!! $lien('assistance.index', 'life-preserver', 'Assistance', 'assistance.*', $nbReponses ?: null) !!}
                {!! $lien('abonnement', 'patch-check', 'Ma licence') !!}
            @endif
        </nav>
    </aside>

    <div class="principal">
        @if ($b && $b->enPeriodeDeGrace())
            <div class="bandeau-essai" style="background:var(--rouge-pale);color:var(--rouge);border-color:#EBC4BF"><i class="bi bi-exclamation-octagon"></i>
                Votre licence a expiré le {{ $b->abonnement_expire_le->format('d/m/Y') }}. Délai de grâce : l'accès sera coupé après le
                <strong>{{ $b->dateCoupure()->format('d/m/Y') }}</strong>. <a href="{{ route('abonnement') }}">Renouveler maintenant</a></div>
        @elseif ($b && $b->statut === 'essai' && $b->estActive())
            <div class="bandeau-essai"><i class="bi bi-info-circle"></i>
                Période d'essai : encore {{ $b->joursRestants() }} jour(s). <a href="{{ route('abonnement') }}">Activer ma licence</a></div>
        @elseif ($b && $b->estActive() && $b->joursRestants() !== null && $b->joursRestants() <= 5)
            <div class="bandeau-essai"><i class="bi bi-exclamation-triangle"></i>
                Votre licence expire le {{ $b->abonnement_expire_le->format('d/m/Y') }}. <a href="{{ route('abonnement') }}">La renouveler</a></div>
        @endif
        @if ($user->doit_changer_mot_de_passe && ! session('rappel_mdp_reporte') && ! request()->routeIs('profil.*'))
            <div class="bandeau-essai bandeau-mdp d-flex flex-wrap align-items-center gap-2" id="rappelMdp" role="status">
                <i class="bi bi-key fs-5"></i>
                <div class="flex-grow-1">
                    <strong>Vous utilisez un mot de passe provisoire</strong> (celui qui vous a été remis à la création de votre compte).
                    <span class="d-block small">Pour le remplacer : cliquez sur votre nom en haut à droite → <strong>Mon profil</strong> → rubrique
                        <strong>« Changer de mot de passe »</strong>. Saisissez le mot de passe provisoire comme « mot de passe actuel », puis votre nouveau mot de passe.</span>
                </div>
                <a href="{{ route('profil.edit') }}#mot-de-passe" class="btn btn-sm btn-primary">Changer maintenant</a>
                <form method="post" action="{{ route('profil.rappel-plus-tard') }}" data-sans-confirmation data-reporter-rappel>@csrf
                    <button class="btn btn-sm btn-light">Me rappeler plus tard</button></form>
            </div>
        @endif
        <header class="haut">
            <button class="btn btn-light d-lg-none" data-ouvrir-menu aria-label="Ouvrir le menu"><i class="bi bi-list"></i></button>
            <div class="fw-semibold text-truncate">@yield('titre')</div>
            @php($nbNotif = $nbAnnonces + $nbReponses + $nbDemandes + $nbNotifs)
            {{-- Pointage de l'employé : arrivée / départ en un clic --}}
            @if (! $estAdmin && $b && fonction('equipe'))
                @php($pointageOuvert = \App\Models\Pointage::where('user_id', $user->id)->whereNull('depart')->latest('arrivee')->first())
                <form method="post" action="{{ route('equipe.pointer') }}" class="ms-auto" data-sans-confirmation>@csrf
                    <button class="btn btn-sm {{ $pointageOuvert ? 'btn-outline-secondary' : 'btn-outline-success' }}"
                            title="{{ $pointageOuvert ? 'Arrivé(e) à '.$pointageOuvert->arrivee->format('H:i') : 'Enregistrer mon heure d\'arrivée' }}">
                        <i class="bi bi-{{ $pointageOuvert ? 'box-arrow-right' : 'person-check' }}"></i>
                        <span class="d-none d-md-inline">{{ $pointageOuvert ? 'Pointer mon départ' : 'Pointer mon arrivée' }}</span></button>
                </form>
            @endif
            <a href="{{ $nbNotifs ? route('notifications.index') : ($estAdmin ? ($nbCommandes ? route('admin.commandes.index') : route('admin.demandes.index')) : ($nbReponses ? route('assistance.index') : route('nouveautes.index'))) }}"
               class="btn btn-light position-relative {{ ! $estAdmin && $b && fonction('equipe') ? '' : 'ms-auto' }}" title="Notifications" aria-label="Notifications ({{ $nbNotif }})">
                <i class="bi bi-bell"></i>
                @if ($nbNotif)<span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">{{ $nbNotif }}</span>@endif
            </a>
            <div class="dropdown">
                <button class="btn btn-link text-decoration-none text-reset d-flex align-items-center gap-2" data-bs-toggle="dropdown">
                    <span class="avatar">{{ $user->initiales() }}</span>
                    <span class="d-none d-sm-inline text-start lh-sm">
                        <span class="d-block fw-semibold">{{ $user->nomComplet() }}</span>
                        <small class="text-doux">{{ $estAdmin ? 'Propriétaire' : $user->role?->nom }}</small>
                    </span>
                    <i class="bi bi-chevron-down small"></i>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="{{ route('profil.edit') }}"><i class="bi bi-person me-2"></i>Mon profil</a></li>
                    <li><a class="dropdown-item" href="{{ route('notifications.index') }}"><i class="bi bi-bell me-2"></i>Notifications
                        @if ($nbNotifs)<span class="badge bg-danger ms-1">{{ $nbNotifs }}</span>@endif</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="post" action="{{ route('logout') }}">@csrf
                            <button class="dropdown-item"><i class="bi bi-box-arrow-right me-2"></i>Se déconnecter</button>
                        </form>
                    </li>
                </ul>
            </div>
        </header>
        <main class="contenu">
            @if ($b)
                {{-- Visible uniquement à l'impression : logo et coordonnées de l'entreprise --}}
                <div class="entete-impression">
                    @if ($b->logoUrl())<img src="{{ $b->logoUrl() }}" alt="">@endif
                    <div><div class="fw-bold fs-5">{{ $b->nom }}</div><div class="small">{{ implode(' · ', $b->coordonnees()) }}</div></div>
                    <div class="ms-auto small text-end">Imprimé le {{ now()->format('d/m/Y à H:i') }}</div>
                </div>
            @endif
            @include('partials.flash')
            @unless ($estAdmin)
                @include('partials.annonce-importante')
                @include('partials.raccourcis')
            @endunless
            @yield('contenu')
        </main>
    </div>
</div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('js/app.js') }}?v=6"></script>
<script src="{{ asset('js/hors-ligne.js') }}?v=1"></script>
@stack('scripts')
</body>
</html>
