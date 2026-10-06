@php
    // Sections liées : depuis une page, un bouton mène directement à ce dont on a souvent besoin ensuite.
    // [route, icône, libellé, permission]
    $s = [
        'caisse' => ['ventes.create', 'cart-plus', 'Nouvelle vente', 'ventes.creer'],
        'ventes' => ['ventes.index', 'receipt', 'Ventes', 'ventes.voir'],
        'credits' => ['credits.index', 'hourglass-split', 'Crédits clients', 'ventes.voir'],
        'clients' => ['clients.index', 'people', 'Clients', 'clients.voir'],
        'produits' => ['produits.index', 'box-seam', 'Produits', 'produits.voir'],
        'categories' => ['categories.index', 'tags', 'Catégories', 'produits.gerer'],
        'appro' => ['approvisionnements.index', 'truck', 'Approvisionnements', 'approvisionnements.gerer'],
        'mouvements' => ['stock.mouvements', 'arrow-left-right', 'Mouvements de stock', 'produits.voir'],
        'inventaire' => ['stock.inventaire', 'clipboard-check', 'Inventaire', 'stock.ajuster'],
        'fournisseurs' => ['fournisseurs.index', 'building', 'Fournisseurs', 'fournisseurs.gerer'],
        'depenses' => ['depenses.index', 'wallet2', 'Dépenses', 'depenses.gerer'],
        'rapports' => ['rapports.index', 'file-earmark-bar-graph', 'Rapports', 'rapports.voir'],
        'dashboard' => ['dashboard', 'grid-1x2', 'Tableau de bord', 'dashboard.voir'],
        'utilisateurs' => ['utilisateurs.index', 'person-badge', 'Utilisateurs', 'utilisateurs.gerer'],
        'roles' => ['roles.index', 'shield-lock', 'Rôles et droits', 'utilisateurs.gerer'],
        'parametres' => ['parametres.edit', 'gear', 'Paramètres', 'parametres.gerer'],
        'dettes' => ['fournisseurs.dettes', 'journal-minus', 'Dettes fournisseurs', 'approvisionnements.gerer'],
        'devis' => ['devis.index', 'file-earmark-text', 'Devis / proforma', 'ventes.voir'],
        'cloture' => ['clotures.create', 'lock', 'Clôturer ma caisse', 'ventes.creer'],
        'commander' => ['stock.a-commander', 'cart-check', 'À commander', 'approvisionnements.gerer'],
        'peremptions' => ['stock.peremptions', 'calendar-x', 'Péremptions', 'produits.voir'],
        'tresorerie' => ['tresorerie.index', 'bank', 'Trésorerie', 'tresorerie.gerer'],
        'promotions' => ['promotions.index', 'percent', 'Promotions', 'produits.gerer'],
        'transferts' => ['transferts.index', 'arrow-left-right', 'Transferts', 'approvisionnements.gerer'],
        'clotures' => ['clotures.index', 'journal-check', 'Clôtures de caisse', 'ventes.voir'],
    ];
    $liens = [
        'dashboard' => ['caisse', 'ventes', 'credits', 'produits', 'depenses', 'rapports'],
        'ventes.create' => ['ventes', 'clients', 'credits', 'cloture'],
        'ventes.*' => ['caisse', 'devis', 'credits', 'clients', 'clotures', 'rapports'],
        'devis.*' => ['caisse', 'ventes', 'clients'],
        'clotures.*' => ['caisse', 'ventes', 'rapports'],
        'credits.*' => ['ventes', 'clients', 'rapports'],
        'clients.*' => ['caisse', 'credits', 'ventes'],
        'produits.*' => ['commander', 'peremptions', 'promotions', 'categories', 'appro', 'inventaire', 'mouvements', 'fournisseurs'],
        'categories.*' => ['produits', 'appro', 'fournisseurs'],
        'approvisionnements.*' => ['commander', 'produits', 'fournisseurs', 'dettes', 'mouvements'],
        'stock.a-commander' => ['appro', 'fournisseurs', 'dettes', 'produits'],
        'promotions.*' => ['produits', 'caisse', 'peremptions', 'rapports'],
        'transferts.*' => ['produits', 'appro', 'mouvements', 'inventaire'],
        'stock.peremptions' => ['produits', 'inventaire', 'mouvements', 'appro'],
        'stock.mouvements' => ['produits', 'inventaire', 'appro'],
        'stock.inventaire' => ['produits', 'mouvements', 'appro'],
        'fournisseurs.*' => ['commander', 'appro', 'dettes', 'produits'],
        'depenses.*' => ['tresorerie', 'rapports', 'dashboard', 'ventes'],
        'tresorerie.*' => ['depenses', 'clotures', 'dettes', 'rapports'],
        'rapports.*' => ['ventes', 'depenses', 'credits', 'produits'],
        'utilisateurs.*' => ['roles', 'parametres'],
        'roles.*' => ['utilisateurs', 'parametres'],
        'parametres.*' => ['utilisateurs', 'roles', 'dashboard'],
        'profil.*' => ['dashboard', 'caisse'],
    ];
    // Raccourcis vers des fonctions réservées : affichés seulement si la formule les inclut
    $fonctionDe = ['promotions' => 'promotions', 'peremptions' => 'peremptions', 'tresorerie' => 'tresorerie'];
    $cles = collect($liens)->first(fn ($v, $motif) => request()->routeIs($motif)) ?? [];
    $raccourcis = collect($cles)->filter(fn ($c) => ! isset($fonctionDe[$c]) || fonction($fonctionDe[$c]))->map(fn ($c) => $s[$c])
        ->filter(fn ($l) => ! request()->routeIs($l[0]) && auth()->user()->aPermission($l[3]));
@endphp
@if ($raccourcis->isNotEmpty())
    <nav class="raccourcis no-print" aria-label="Accès rapide aux autres sections">
        <span class="raccourcis-titre"><i class="bi bi-signpost-split"></i> Accès rapide</span>
        @foreach ($raccourcis as [$route, $icone, $texte])
            <a href="{{ route($route) }}" class="raccourci"><i class="bi bi-{{ $icone }}"></i>{{ $texte }}</a>
        @endforeach
    </nav>
@endif
