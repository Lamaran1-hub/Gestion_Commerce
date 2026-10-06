<!doctype html>
<html lang="fr">
<head>
    @include('layouts.tete', ['marque' => $b])
    <title>{{ $b->nom }} · Catalogue et commande en ligne</title>
    <meta name="description" content="Catalogue de {{ $b->nom }}{{ $b->ville ? ' à '.$b->ville : '' }} : prix, disponibilité et commande par WhatsApp.">
    <style>
        body { background: var(--papier); }
        .vitrine-haut { background: var(--foret); color: #fff; padding: 1rem; }
        .vitrine-haut img { width: 52px; height: 52px; border-radius: 12px; background: #fff; object-fit: contain; padding: 3px; }
        .puce { border: 1px solid var(--ligne); background: #fff; border-radius: 99px; padding: .35rem .8rem; font-size: .85rem; white-space: nowrap; }
        .puce.active { background: var(--marque); border-color: var(--marque); color: #fff; }
        .carte-produit { background: #fff; border: 1px solid var(--ligne); border-radius: 12px; overflow: hidden; height: 100%; display: flex; flex-direction: column; }
        .carte-produit .photo { aspect-ratio: 4/3; background: #EEF2EF; display: grid; place-items: center; color: var(--doux); font-size: 2rem; }
        .carte-produit .photo img { width: 100%; height: 100%; object-fit: cover; }
        .panier-flottant { position: fixed; left: 50%; transform: translateX(-50%); bottom: 16px; z-index: 30; box-shadow: 0 6px 20px rgba(0,0,0,.25); }
        .filtres { overflow-x: auto; scrollbar-width: none; } .filtres::-webkit-scrollbar { display: none; }
        main { padding-bottom: 6rem; }
    </style>
</head>
<body>
    <header class="vitrine-haut">
        <div class="container d-flex align-items-center gap-3" style="max-width:1100px">
            @if ($b->logoUrl())<img src="{{ $b->logoUrl() }}" alt="">@else<div class="barre-initiales" style="width:52px;height:52px">{{ $b->initiales() }}</div>@endif
            <div class="flex-grow-1 min-w-0">
                <h1 class="h5 mb-0 text-truncate">{{ $b->nom }}</h1>
                <div class="small" style="color:#C9D8D0">{{ collect([$b->adresse, $b->ville])->filter()->implode(', ') }}</div>
            </div>
            @if ($b->telephone)<a href="tel:{{ $b->telephone }}" class="btn btn-sm btn-light" aria-label="Appeler la boutique"><i class="bi bi-telephone"></i><span class="d-none d-sm-inline ms-1">{{ $b->telephone }}</span></a>@endif
        </div>
    </header>
    <main class="container py-3" style="max-width:1100px">
        @if ($errors->any())<div class="alert alert-danger py-2" role="alert">{{ $errors->first() }}</div>@endif
        @if ($b->vitrine_message)<div class="alert alert-success py-2">{{ $b->vitrine_message }}</div>@endif
        <div class="input-group mb-2">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input id="recherche" class="form-control" placeholder="Rechercher un produit" aria-label="Rechercher un produit">
        </div>
        @if ($categories->isNotEmpty())
            <div class="filtres d-flex gap-2 mb-3" role="group" aria-label="Catégories">
                <button type="button" class="puce active" data-categorie="">Tout</button>
                @foreach ($categories as $c)<button type="button" class="puce" data-categorie="{{ $c->id }}">{{ $c->nom }}</button>@endforeach
            </div>
        @endif
        <div class="row g-2 g-sm-3" id="grille"></div>
        <p class="vide d-none" id="aucun">Aucun produit ne correspond.</p>
        <p class="small text-doux mt-4 mb-0">Prix en francs guinéens. La commande est confirmée par la boutique (disponibilité, livraison, paiement).</p>
    </main>

    <button type="button" class="btn btn-primary btn-lg panier-flottant d-none" id="ouvrirPanier" data-bs-toggle="offcanvas" data-bs-target="#panier">
        <i class="bi bi-bag me-1"></i>Mon panier · <span id="nbPanier">0</span> · <span id="totalPanier">0 GNF</span></button>

    <div class="offcanvas offcanvas-bottom h-auto" tabindex="-1" id="panier" aria-labelledby="titrePanier" style="max-height:90vh">
        <div class="offcanvas-header"><h2 class="offcanvas-title h5" id="titrePanier">Mon panier</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Fermer"></button></div>
        <div class="offcanvas-body pt-0">
            <div id="lignesPanier" class="mb-3"></div>
            <form method="post" action="{{ route('vitrine.commander', $b->slug) }}" id="formCommande" class="row g-2">
                @csrf
                {!! \App\Support\AntiRobot::champs() !!}
                <div id="champsPanier"></div>
                <div class="col-sm-6"><label class="form-label small" for="nom">Votre nom</label>
                    <input name="nom" id="nom" class="form-control" required maxlength="120" autocomplete="name" value="{{ old('nom') }}"></div>
                <div class="col-sm-6"><label class="form-label small" for="telephone">Votre téléphone</label>
                    <input name="telephone" id="telephone" class="form-control" required inputmode="tel" autocomplete="tel" placeholder="6XX XX XX XX" value="{{ old('telephone') }}"></div>
                <div class="col-12"><label class="form-label small" for="note">Précisions (livraison, quartier…)</label>
                    <input name="note" id="note" class="form-control" maxlength="500" value="{{ old('note') }}"></div>
                <div class="col-12 d-grid"><button class="btn btn-success btn-lg"><i class="bi bi-whatsapp me-1"></i>Envoyer ma commande</button></div>
                <div class="col-12 small text-doux">Votre commande est transmise à la boutique, qui vous recontacte pour confirmer.</div>
            </form>
        </div>
    </div>

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/app.js') }}?v=6"></script>
    <script>
    (() => {
        const produits = {{ Js::from($produits) }};
        const cle = 'gn-panier-{{ $b->slug }}';
        let panier = {};
        try { panier = JSON.parse(localStorage.getItem(cle) || '{}') || {}; } catch { panier = {}; }
        const sauver = () => { try { localStorage.setItem(cle, JSON.stringify(panier)); } catch {} };
        const $ = (id) => document.getElementById(id);
        const prix = (p) => p.promo && p.promo < p.prix ? p.promo : p.prix;
        let categorie = '';

        function carte(p) {
            const dispo = { ok: ['Disponible', 'text-success'], limite: ['Plus que quelques-uns', 'text-warning-emphasis'], rupture: ['En rupture', 'text-danger'] }[p.dispo];
            const q = panier[p.id] || 0;
            return `<div class="col-6 col-md-4 col-lg-3"><article class="carte-produit">
                <div class="photo">${p.image ? `<img src="${echapper(p.image)}" alt="" loading="lazy">` : '<i class="bi bi-box-seam"></i>'}</div>
                <div class="p-2 d-flex flex-column flex-grow-1">
                    <h3 class="h6 mb-1">${echapper(p.nom)}</h3>
                    <div class="fw-bold">${p.promo && p.promo < p.prix ? `<s class="text-doux fw-normal small">${gnf(p.prix)}</s> <span class="text-danger">${gnf(p.promo)}</span>` : gnf(p.prix)}
                        <span class="small text-doux fw-normal">/ ${echapper(p.unite)}</span></div>
                    <div class="small ${dispo[1]}">${dispo[0]}${p.stock !== null && p.dispo !== 'rupture' ? ' (' + p.stock.toLocaleString('fr-FR') + ')' : ''}</div>
                    <div class="mt-auto pt-2">${p.dispo === 'rupture' ? '' : q
                        ? `<div class="input-group input-group-sm"><button class="btn btn-outline-primary" data-moins="${p.id}" aria-label="Retirer un">−</button>
                             <span class="form-control text-center">${q}</span><button class="btn btn-outline-primary" data-plus="${p.id}" aria-label="Ajouter un">+</button></div>`
                        : `<button class="btn btn-sm btn-primary w-100" data-plus="${p.id}"><i class="bi bi-bag-plus me-1"></i>Ajouter</button>`}</div>
                </div></article></div>`;
        }

        function rendre() {
            const t = $('recherche').value.trim().toLowerCase();
            const liste = produits.filter(p => (!categorie || String(p.categorie) === categorie) && (!t || p.nom.toLowerCase().includes(t)));
            $('grille').innerHTML = liste.map(carte).join('');
            $('aucun').classList.toggle('d-none', liste.length > 0);
            const lignes = Object.entries(panier).map(([id, q]) => ({ p: produits.find(x => x.id == id), q })).filter(l => l.p);
            const total = lignes.reduce((s, l) => s + prix(l.p) * l.q, 0);
            const nb = lignes.reduce((s, l) => s + l.q, 0);
            $('nbPanier').textContent = nb + ' article' + (nb > 1 ? 's' : '');
            $('totalPanier').textContent = gnf(total);
            $('ouvrirPanier').classList.toggle('d-none', nb === 0);
            $('lignesPanier').innerHTML = lignes.length ? lignes.map(l => `<div class="d-flex justify-content-between align-items-center border-bottom py-2 gap-2">
                <div class="small"><div class="fw-semibold">${echapper(l.p.nom)}</div>${l.q} × ${gnf(prix(l.p))}</div>
                <div class="d-flex align-items-center gap-2"><span class="fw-semibold text-nowrap">${gnf(prix(l.p) * l.q)}</span>
                <button type="button" class="btn btn-sm btn-link text-danger p-0" data-supprimer="${l.p.id}" aria-label="Retirer ${echapper(l.p.nom)}">✕</button></div></div>`).join('')
                + `<div class="d-flex justify-content-between fw-bold pt-2"><span>Total estimé</span><span>${gnf(total)}</span></div>` : '<p class="text-doux">Panier vide.</p>';
            $('champsPanier').innerHTML = lignes.map((l, i) => `<input type="hidden" name="lignes[${i}][produit_id]" value="${l.p.id}"><input type="hidden" name="lignes[${i}][quantite]" value="${l.q}">`).join('');
        }

        document.addEventListener('click', (e) => {
            const plus = e.target.closest('[data-plus]'), moins = e.target.closest('[data-moins]'), sup = e.target.closest('[data-supprimer]'), cat = e.target.closest('[data-categorie]');
            if (plus) panier[plus.dataset.plus] = (panier[plus.dataset.plus] || 0) + 1;
            if (moins) { const id = moins.dataset.moins; panier[id] = (panier[id] || 0) - 1; if (panier[id] <= 0) delete panier[id]; }
            if (sup) delete panier[sup.dataset.supprimer];
            if (cat) { categorie = cat.dataset.categorie; document.querySelectorAll('[data-categorie]').forEach(b => b.classList.toggle('active', b === cat)); }
            if (plus || moins || sup || cat) { sauver(); rendre(); }
        });
        $('recherche').addEventListener('input', rendre);
        rendre();
    })();
    </script>
</body>
</html>
