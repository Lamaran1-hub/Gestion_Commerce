/*
 * Service worker : la caisse reste utilisable sans connexion.
 * - Fichiers du site (styles, scripts, polices, images) : servis depuis l'appareil, mis à jour en arrière-plan.
 * - Page de caisse : réseau d'abord ; sans réseau, dernière version gardée sur l'appareil.
 * - Autres pages sans réseau : page d'explication avec un lien vers la caisse.
 * Rien d'autre n'est mis en cache (aucune donnée de gestion hors de la caisse).
 */
const STATIQUE = 'gn-statique-v4';
const PAGES = 'gn-caisse-v1';
const FICHIERS = [
    '/vendor/bootstrap/bootstrap.min.css', '/vendor/bootstrap/bootstrap.bundle.min.js',
    '/vendor/bootstrap-icons/bootstrap-icons.min.css', '/vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
    '/vendor/public-sans/public-sans.css', '/css/app.css', '/js/app.js', '/js/hors-ligne.js', '/hors-ligne.html',
];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(STATIQUE).then((c) => c.addAll(FICHIERS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys()
        .then((cles) => Promise.all(cles.filter((c) => c.startsWith('gn-') && ![STATIQUE, PAGES].includes(c)).map((c) => caches.delete(c))))
        .then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;

    if (req.mode === 'navigate') {
        if (url.pathname === '/caisse') {
            e.respondWith(fetch(req).then((r) => {
                // Page de caisse à jour (pas une redirection vers la connexion) : gardée pour le hors connexion
                if (r.ok && !r.redirected) {
                    const copie = r.clone();
                    caches.open(PAGES).then((c) => c.put('/caisse', copie));
                }
                return r;
            }).catch(() => caches.open(PAGES).then((c) => c.match('/caisse')).then((r) => r || caches.match('/hors-ligne.html'))));
        } else {
            e.respondWith(fetch(req).catch(() => caches.match('/hors-ligne.html')));
        }
        return;
    }

    if (/^\/(vendor|css|js|img|storage)\//.test(url.pathname)) {
        // Clé = adresse complète (avec ?v=…) : une nouvelle version du fichier est chargée tout de suite ;
        // sans réseau, on retombe sur n'importe quelle version gardée du même fichier.
        e.respondWith(caches.open(STATIQUE).then((c) => c.match(req).then((garde) => {
            const reseau = fetch(req).then((r) => {
                if (r.ok) {
                    c.put(req, r.clone());
                    c.put(url.pathname, r.clone());
                }
                return r;
            }).catch(() => garde || c.match(url.pathname));
            return garde || reseau;
        })));
    }
});
