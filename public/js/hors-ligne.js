/*
 * Ventes faites sans connexion : gardées sur l'appareil (par boutique et par caissier),
 * puis envoyées au serveur dès que le réseau revient, sur n'importe quelle page.
 * Une vente n'est retirée de l'appareil qu'une fois confirmée par le serveur.
 */
(() => {
    const corps = document.body;
    const utilisateur = corps.dataset.utilisateur;
    if (!utilisateur || !corps.dataset.urlSync) return;

    if ('serviceWorker' in navigator && corps.dataset.urlSw && corps.dataset.horsLigne === '1') {
        navigator.serviceWorker.register(corps.dataset.urlSw).catch(() => {});
    }

    const cle = `gn-hors-ligne-${corps.dataset.boutique}-${utilisateur}`;
    const lire = () => { try { return JSON.parse(localStorage.getItem(cle) || '[]'); } catch { return []; } };
    const ecrire = (liste) => {
        try { localStorage.setItem(cle, JSON.stringify(liste)); return true; } catch { return false; }
    };
    const echapper = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const gnf = (n) => Math.round(n).toLocaleString('fr-FR') + ' GNF';
    const uuid = () => (crypto.randomUUID ? crypto.randomUUID()
        : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16); }));

    let jeton = document.querySelector('meta[name=csrf-token]')?.content;
    let enCours = false;
    let message = '';

    /** Le serveur répond-il ? (et session toujours ouverte) → 'ok' | 'session' | 'reseau' */
    async function etatServeur() {
        try {
            const r = await fetch(corps.dataset.urlPing, { cache: 'no-store', credentials: 'same-origin', headers: { Accept: 'application/json' },
                signal: AbortSignal.timeout ? AbortSignal.timeout(4000) : undefined });
            if (r.status === 401 || r.redirected) return 'session';
            if (!r.ok) return 'reseau';
            jeton = (await r.json()).jeton || jeton;
            document.querySelectorAll('input[name=_token]').forEach((i) => { i.value = jeton; });
            const meta = document.querySelector('meta[name=csrf-token]');
            if (meta) meta.content = jeton;
            return 'ok';
        } catch { return 'reseau'; }
    }

    async function synchroniser() {
        const aEnvoyer = lire().filter((v) => !v.erreur);
        if (!aEnvoyer.length || enCours) { afficher(); return; }
        enCours = true;
        try {
            const etat = await etatServeur();
            if (etat === 'session') { message = 'Session expirée : reconnectez-vous pour envoyer les ventes gardées sur cet appareil.'; return; }
            if (etat !== 'ok') { message = ''; return; }
            const r = await fetch(corps.dataset.urlSync, {
                method: 'POST', credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jeton },
                body: JSON.stringify({ ventes: aEnvoyer.map(({ erreur, total_affiche, client_nom, ...v }) => v) }),
            });
            if (r.status === 422) {
                const d = await r.json().catch(() => ({}));
                message = 'Envoi refusé : ' + (d.message || 'données invalides') + '. Contactez l\'administrateur.';
                return;
            }
            if (!r.ok) { message = r.status === 401 || r.status === 419 ? 'Session expirée : reconnectez-vous pour envoyer les ventes.' : ''; return; }
            const { resultats } = await r.json();
            const liste = lire();
            const envoyees = [];
            resultats.forEach((res) => {
                const i = liste.findIndex((v) => v.uuid === res.uuid);
                if (i < 0) return;
                if (res.statut === 'erreur') { liste[i].erreur = res.message; return; }
                envoyees.push(res);
                liste.splice(i, 1);
            });
            ecrire(liste);
            const alertes = envoyees.flatMap((e) => e.alertes || []);
            message = envoyees.length ? `${envoyees.length} vente(s) hors connexion envoyée(s) : ${envoyees.map((e) => e.numero).join(', ')}.`
                + (alertes.length ? ' À vérifier : ' + alertes.join(' ; ') + '.' : '') : '';
        } catch { /* réseau coupé pendant l'envoi : on réessaiera */ } finally {
            enCours = false;
            afficher();
        }
    }

    function afficher() {
        const liste = lire();
        let bandeau = document.getElementById('bandeauHorsLigne');
        if (!liste.length && !message) { bandeau?.remove(); return; }
        if (!bandeau) {
            bandeau = document.createElement('div');
            bandeau.id = 'bandeauHorsLigne';
            bandeau.setAttribute('role', 'status');
            bandeau.className = 'bandeau-hors-ligne';
            document.body.append(bandeau);
        }
        const erreurs = liste.filter((v) => v.erreur);
        const attente = liste.length - erreurs.length;
        bandeau.innerHTML = `
            <div class="d-flex justify-content-between gap-2 align-items-start">
                <div>${attente ? `<strong><i class="bi bi-cloud-arrow-up me-1"></i>${attente} vente(s) hors connexion</strong> en attente d'envoi (${gnf(liste.filter((v) => !v.erreur).reduce((s, v) => s + (v.total_affiche || 0), 0))}).` : ''}
                    ${message ? `<div class="small mt-1">${echapper(message)}</div>` : ''}</div>
                <div class="d-flex gap-1">${attente ? '<button type="button" class="btn btn-sm btn-light" data-hl="envoyer">Envoyer</button>' : ''}
                    <button type="button" class="btn btn-sm btn-link text-reset p-0" data-hl="fermer" aria-label="Masquer">✕</button></div>
            </div>
            ${erreurs.map((v) => `<div class="small mt-2 border-top pt-2"><strong>Refusée</strong> — vente du ${new Date(v.cree_le).toLocaleString('fr-FR')} (${gnf(v.total_affiche || 0)}) : ${echapper(v.erreur)}
                <div class="mt-1"><button type="button" class="btn btn-sm btn-light" data-hl="reessayer" data-uuid="${v.uuid}">Réessayer</button>
                <button type="button" class="btn btn-sm btn-link text-reset" data-hl="supprimer" data-uuid="${v.uuid}">Supprimer (saisie manuelle faite)</button></div></div>`).join('')}`;
    }

    document.addEventListener('click', (e) => {
        const b = e.target.closest('[data-hl]');
        if (!b) return;
        const action = b.dataset.hl;
        if (action === 'envoyer') synchroniser();
        if (action === 'fermer') { message = ''; document.getElementById('bandeauHorsLigne')?.remove(); }
        if (action === 'reessayer') { ecrire(lire().map((v) => (v.uuid === b.dataset.uuid ? { ...v, erreur: undefined } : v))); synchroniser(); }
        if (action === 'supprimer' && confirm('Supprimer définitivement cette vente de l\'appareil ? Faites-le seulement si elle a été saisie à la main.')) {
            ecrire(lire().filter((v) => v.uuid !== b.dataset.uuid));
            afficher();
        }
    });

    window.HorsLigne = {
        uuid,
        file: lire,
        /** Garde une vente sur l'appareil ; false si l'espace de stockage est plein. */
        ajouter(vente) {
            const liste = lire();
            liste.push(vente);
            const ok = ecrire(liste);
            afficher();
            return ok;
        },
        etatServeur,
        synchroniser,
    };

    window.addEventListener('online', synchroniser);
    synchroniser();
    setInterval(synchroniser, 60000);
})();
