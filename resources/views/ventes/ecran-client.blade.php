<!doctype html>
<html lang="fr">
<head>
    @include('layouts.tete', ['marque' => $b])
    <title>Écran client · {{ $b->nom }}</title>
    <meta name="robots" content="noindex">
    <style>
        html, body { height: 100%; }
        body { background: var(--papier); overflow: hidden; font-size: clamp(16px, 1.6vw, 22px); }
        .ecran { height: 100vh; height: 100dvh; display: flex; flex-direction: column; }
        .ecran-haut { background: var(--foret); color: #fff; padding: .8rem 1.2rem; display: flex; align-items: center; gap: 1rem; }
        .ecran-haut img, .ecran-haut .barre-initiales { width: 3rem; height: 3rem; border-radius: .6rem; background: #fff; object-fit: contain; padding: 2px; flex: none; }
        .ecran-corps { flex: 1; min-height: 0; display: flex; flex-direction: column; padding: 1rem 1.2rem; }
        .lignes { flex: 1; min-height: 0; overflow-y: auto; }
        .ligne { display: flex; align-items: center; gap: .9rem; padding: .55rem 0; border-bottom: 1px solid var(--ligne); }
        .ligne .vignette { width: 3.4em; height: 3.4em; flex: none; border-radius: .6rem; background: #fff; border: 1px solid var(--ligne); object-fit: contain;
            display: flex; align-items: center; justify-content: center; color: var(--doux); font-size: 1em; overflow: hidden; }
        .ligne .vignette i { font-size: 1.4em; }
        /* Grand écran posé à l'horizontale : la photo du dernier article ajouté, en grand */
        .ticket-grille { flex: 1; min-height: 0; display: flex; gap: 1.2rem; }
        .vedette { display: none; }
        @media (orientation: landscape) and (min-width: 900px) {
            .vedette { display: flex; flex-direction: column; width: 34%; max-width: 26rem; background: #fff; border: 1px solid var(--ligne); border-radius: 1rem; padding: 1rem; text-align: center; }
            .vedette .photo { flex: 1; min-height: 0; display: flex; align-items: center; justify-content: center; }
            .vedette img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: .6rem; }
            .vedette .photo i { font-size: 6em; color: var(--ligne); }
        }
        .ligne .detail { color: var(--doux); font-size: .85em; }
        .ligne.nouvelle { animation: surligner 1.2s ease-out; }
        @keyframes surligner { from { background: rgba(31, 111, 84, .18); } to { background: transparent; } }
        .pied { border-top: 3px solid var(--marque); padding-top: .8rem; }
        .total { font-size: clamp(2rem, 6vw, 4.5rem); font-weight: 800; line-height: 1.05; color: var(--marque); }
        .etat-accueil, .etat-merci { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: .6rem; }
        .promo { background: #fff; border: 1px solid var(--ligne); border-radius: .8rem; padding: .7rem 1rem; }
        @media (orientation: portrait) { .ecran-haut { padding: .6rem .9rem; } }
    </style>
</head>
<body>
<div class="ecran">
    <header class="ecran-haut">
        @if ($b->logoUrl())<img src="{{ $b->logoUrl() }}" alt="">@else<div class="barre-initiales text-dark">{{ $b->initiales() }}</div>@endif
        <div class="flex-grow-1 min-w-0"><div class="fw-bold fs-5 text-truncate">{{ $b->nom }}</div>
            <div class="small text-truncate" style="color:#C9D8D0">{{ collect([$b->adresse, $b->ville, $b->telephone])->filter()->implode(' · ') }}</div></div>
        <div class="text-end small d-none d-sm-block" id="heure" aria-hidden="true"></div>
        <button type="button" class="btn btn-sm btn-outline-light opacity-50" id="pleinEcran" title="Plein écran" aria-label="Plein écran"><i class="bi bi-arrows-fullscreen"></i></button>
    </header>

    <main class="ecran-corps">
        {{-- Attente : accueil, promotions en cours, vitrine --}}
        <section class="etat-accueil" id="etatAccueil" aria-live="polite">
            <div class="display-6 fw-bold">Bienvenue</div>
            <div class="text-doux">Votre ticket s'affichera ici pendant l'encaissement.</div>
            @if ($promos->isNotEmpty())
                <div class="mt-3 w-100" style="max-width:900px">
                    <div class="fw-semibold mb-2"><i class="bi bi-percent me-1"></i>En promotion en ce moment</div>
                    <div class="row g-2 justify-content-center">
                        @foreach ($promos as $p)
                            <div class="col-6 col-lg-4"><div class="promo h-100">@if ($p['img'])<img src="{{ $p['img'] }}" alt="" class="d-block mx-auto mb-1 rounded" style="height:4.5em;max-width:100%;object-fit:contain">@endif<div class="fw-semibold">{{ $p['nom'] }}</div>
                                <s class="text-doux small">{{ gnf($p['prix']) }}</s> <span class="text-danger fw-bold">{{ gnf($p['promo']) }}</span></div></div>
                        @endforeach
                    </div>
                </div>
            @endif
            @if ($lienVitrine)
                <div class="mt-3 small text-doux">Commandez aussi depuis votre téléphone : <strong class="text-body">{{ preg_replace('#^https?://#', '', $lienVitrine) }}</strong></div>
            @endif
        </section>

        {{-- Ticket en cours --}}
        <section class="d-none flex-column flex-grow-1" style="min-height:0" id="etatTicket" aria-label="Ticket en cours">
            <div class="d-flex justify-content-between align-items-baseline mb-1">
                <div class="fw-bold fs-5">Votre ticket</div><div class="text-doux" id="client"></div>
            </div>
            <div class="ticket-grille">
                <aside class="vedette" id="vedette" aria-label="Dernier article ajouté">
                    <div class="small text-doux">Dernier article ajouté</div>
                    <div class="photo" id="vedettePhoto"></div>
                    <div class="fw-bold mt-2" id="vedetteNom"></div>
                    <div class="text-doux" id="vedettePrix"></div>
                </aside>
                <div class="lignes flex-grow-1" id="lignes"></div>
            </div>
            <div class="pied">
                <div class="d-flex justify-content-between text-doux" id="ligneSousTotal"><span>Sous-total</span><span id="sousTotal"></span></div>
                <div class="d-flex justify-content-between text-danger d-none" id="ligneRemise"><span>Remise</span><span id="remise"></span></div>
                <div class="d-flex justify-content-between text-doux d-none" id="ligneTva"><span>{{ \App\Services\VenteService::prixTtc() ? 'dont TVA' : 'TVA' }}</span><span id="tva"></span></div>
                <div class="d-flex justify-content-between align-items-end flex-wrap gap-2 mt-1">
                    <span class="fs-4 fw-semibold">Total à payer</span><span class="total montant" id="total" aria-live="polite"></span>
                </div>
                <div class="d-flex justify-content-between fs-5 text-success d-none" id="ligneDeduit"><span>Payé avec votre avoir / vos points</span><span id="deduit"></span></div>
                <div class="d-flex justify-content-between fs-4 fw-semibold d-none" id="ligneReste"><span>Reste à payer</span><span id="reste"></span></div>
                <div class="d-flex justify-content-between fs-5 mt-1 d-none" id="ligneRecu"><span>Reçu</span><span id="recu"></span></div>
                <div class="d-flex justify-content-between fs-4 fw-bold text-success d-none" id="ligneMonnaie"><span>Monnaie à rendre</span><span id="monnaie"></span></div>
            </div>
        </section>

        {{-- Vente validée --}}
        <section class="etat-merci d-none" id="etatMerci" aria-live="assertive">
            <i class="bi bi-check-circle-fill text-success" style="font-size:4em"></i>
            <div class="display-5 fw-bold">Merci pour votre achat !</div>
            <div class="fs-4" id="merciTotal"></div>
            <div class="fs-3 fw-bold text-success d-none" id="merciMonnaie"></div>
            <div class="text-doux">À bientôt chez {{ $b->nom }}.</div>
        </section>
    </main>
</div>


<script src="{{ asset('js/app.js') }}?v=6"></script>
<script>
(() => {
    const $ = (id) => document.getElementById(id);
    const canal = new BroadcastChannel('gn-ecran-client-{{ $b->id }}-{{ auth()->id() }}');
    let idsAvant = new Set(), minuterieMerci = null, derniereMonnaie = 0, merciDepuis = 0;

    const montrer = (etat) => {
        $('etatAccueil').classList.toggle('d-none', etat !== 'accueil');
        $('etatTicket').classList.toggle('d-none', etat !== 'ticket');
        $('etatTicket').classList.toggle('d-flex', etat === 'ticket');
        $('etatMerci').classList.toggle('d-none', etat !== 'merci');
    };
    const ligne = (id, visible) => $(id).classList.toggle('d-none', !visible);
    // Photo du produit (ou pictogramme s'il n'en a pas) : le client reconnaît ce qu'il achète
    const vignette = (l) => l.img ? `<img class="vignette" src="${echapper(l.img)}" alt="" loading="lazy">` : '<span class="vignette" aria-hidden="true"><i class="bi bi-box-seam"></i></span>';

    function afficherTicket(t) {
        if (!t.lignes.length) {
            if (Date.now() - merciDepuis < 12000) return;   // le « Merci » reste affiché même si la caisse est déjà revenue
            montrer('accueil'); idsAvant = new Set(); return;
        }
        merciDepuis = 0;
        clearTimeout(minuterieMerci);
        montrer('ticket');
        $('client').textContent = t.client || '';
        $('lignes').innerHTML = t.lignes.map(l => `<div class="ligne ${idsAvant.has(l.cle) ? '' : 'nouvelle'}">
            ${vignette(l)}
            <div class="flex-grow-1 min-w-0"><div class="fw-semibold">${echapper(l.nom)}</div><div class="detail">${echapper(l.quantite)} × ${gnf(l.prix)}${l.promo ? ' <span class="text-danger">promo</span>' : ''}</div></div>
            <div class="fw-semibold text-nowrap montant">${gnf(l.total)}</div></div>`).join('');
        const nouvelle = $('lignes').querySelector('.nouvelle');
        (nouvelle || $('lignes').lastElementChild)?.scrollIntoView({ block: 'nearest' });
        // Photo en grand : le dernier article ajouté (ou modifié), sinon le dernier du ticket
        const vedette = t.lignes.find(l => !idsAvant.has(l.cle)) || t.lignes[t.lignes.length - 1];
        $('vedettePhoto').innerHTML = vedette.img ? `<img src="${echapper(vedette.img)}" alt="${echapper(vedette.nom)}">` : '<i class="bi bi-box-seam" aria-hidden="true"></i>';
        $('vedetteNom').textContent = vedette.nom;
        $('vedettePrix').textContent = vedette.quantite + ' × ' + gnf(vedette.prix);
        idsAvant = new Set(t.lignes.map(l => l.cle));
        $('sousTotal').textContent = gnf(t.sousTotal);
        $('remise').textContent = '− ' + gnf(t.remise); ligne('ligneRemise', t.remise > 0);
        $('tva').textContent = gnf(t.tva); ligne('ligneTva', t.tva > 0);
        $('total').textContent = gnf(t.total);
        $('deduit').textContent = '− ' + gnf(t.deduit || 0); ligne('ligneDeduit', (t.deduit || 0) > 0);
        $('reste').textContent = gnf(t.resteAPayer ?? t.total); ligne('ligneReste', (t.deduit || 0) > 0);
        $('recu').textContent = gnf(t.recu); ligne('ligneRecu', t.monnaie > 0);
        $('monnaie').textContent = gnf(t.monnaie); ligne('ligneMonnaie', t.monnaie > 0);
        derniereMonnaie = t.monnaie || 0;
    }

    function afficherMerci(m) {
        montrer('merci');
        merciDepuis = Date.now();
        $('merciTotal').textContent = 'Total payé : ' + gnf(m.total);
        $('merciMonnaie').textContent = 'Monnaie rendue : ' + gnf(m.monnaie ?? derniereMonnaie);
        $('merciMonnaie').classList.toggle('d-none', !(m.monnaie ?? derniereMonnaie));
        idsAvant = new Set();
        clearTimeout(minuterieMerci);
        minuterieMerci = setTimeout(() => montrer('accueil'), 12000);
    }

    canal.onmessage = ({ data }) => {
        if (data?.type === 'ticket') afficherTicket(data.ticket);
        if (data?.type === 'merci') afficherMerci(data);
    };
    canal.postMessage({ type: 'demande' });   // la caisse déjà ouverte renvoie son ticket

    // Horloge, plein écran, écran toujours allumé
    const heure = () => $('heure').textContent = new Date().toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
    heure(); setInterval(heure, 15000);
    $('pleinEcran').addEventListener('click', () => document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.());
    const garderAllume = async () => { try { await navigator.wakeLock?.request('screen'); } catch {} };
    garderAllume();
    document.addEventListener('visibilitychange', () => document.visibilityState === 'visible' && garderAllume());
})();
</script>
</body>
</html>
