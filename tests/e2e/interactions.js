// À lancer UNIQUEMENT sur une copie de test (port 8012) : ce script clique sur tout et soumet tous les formulaires.
// Pour chaque page : boutons, menus déroulants, onglets, blocs repliables, fenêtres, confirmations ; puis chaque formulaire
// est rempli avec des valeurs plausibles et envoyé. Attendu : un message de succès ou un refus clair, jamais une erreur serveur.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8012';
if (!(new URL(BASE).port === '8012') && process.env.E2E_COPIE_DE_TEST !== 'oui') {
  console.error('Refusé : ' + BASE + " n'est pas la copie de test (port 8012). Ce script modifie les données.");
  process.exit(1);
}
const OUT = path.join(__dirname, '..', '..', 'storage', 'logs', 'e2e-interactions');
fs.mkdirSync(OUT, { recursive: true });

// Modes (copie jetable uniquement) :
//   par défaut      : espace boutique, compte de démonstration ;
//   E2E_TOUT=1      : envoie aussi les formulaires de réglages (paramètres, utilisateurs, rôles, intégrité…) ;
//   E2E_ADMIN=1     : espace propriétaire /admin (identifiants E2E_EMAIL / E2E_MDP) ;
//   E2E_PUBLIC=1    : pages publiques sans connexion (accueil, inscription, mot de passe oublié, vitrine).
const MODE = process.env.E2E_ADMIN ? 'admin' : process.env.E2E_PUBLIC ? 'public' : 'boutique';
// Pages jamais visitées et formulaires jamais envoyés : déconnexion, réglages sensibles, droits, abonnement, suppressions
const PAGES_EXCLUES_BOUTIQUE = /deconnexion|logout|wa\.me|tel:|mailto:|\/export|\.pdf|\/proforma|\/facture|\/bon|\/recu|bon-avoir|releve|ecran-client|telecharger|sauvegarde|\/admin/i;
const PAGES_EXCLUES = MODE === 'admin'
  ? /deconnexion|logout|wa\.me|tel:|mailto:|\/export|\.pdf|telecharger|\/sauvegardes\/.+|ouvrir-session|\/se-connecter-comme/i
  : PAGES_EXCLUES_BOUTIQUE;
const FORMULAIRES_EXCLUS = MODE === 'admin'
  // Propriétaire : pas d'e-mail réel, pas d'appel à Djomy, pas de sauvegarde (fichiers partagés avec la vraie installation)
  ? /deconnexion|\/profil|\/emails|\/sauvegardes|diagnostic|\/installation|ouvrir-session|se-connecter-comme/i
  : process.env.E2E_TOUT
    // Réglages compris ; restent exclus : mot de passe, paiement en ligne réel, clôture (bloquerait la caisse pour la suite)
    ? /deconnexion|\/profil|\/sauvegarde|\/licence|\/abonnement|\/admin|\/caisse\/cloture|\/clotures|\/installation|\/emails|desabonner/i
    : /deconnexion|\/parametres|\/profil|\/utilisateurs|\/roles|\/mes-boutiques|\/integrite|\/sauvegarde|\/licence|\/abonnement|\/admin|\/caisse\/cloture|\/clotures|\/installation|\/emails|desabonner|\/rouvrir|\/archives/i;

const unique = () => String(Date.now()).slice(-7);
const anomalies = [];
const journal = [];   // détail de chaque formulaire envoyé
const stats = { pages: 0, clics: 0, liens: 0, filtres: 0, formulaires: 0, succes: 0, refus: 0, sansEffet: 0 };
const liensVus = new Set();

async function remplir(page, form) {
  return form.evaluate((f, u) => {
    const aujourdhui = new Date().toISOString().slice(0, 10);
    const val = (el) => {
      const n = (el.name || '').toLowerCase();
      if (el.type === 'date') return el.min && el.min > aujourdhui ? el.min : (el.max && el.max < aujourdhui ? el.max : aujourdhui);
      if (el.type === 'email' || n.includes('email')) return 'e2e' + u + '@exemple.gn';
      if (n.includes('telephone') || el.type === 'tel') return '62' + u;
      if (/montant|prix|plafond|objectif|frais/.test(n) || el.hasAttribute('data-montant')) return '10 000';
      if (/quantite|qte|seuil|stock|garantie|nombre|duree|jours|heures|mois|pct|taux|points/.test(n) || el.type === 'number') {
        const min = parseFloat(el.min); return String(Number.isFinite(min) && min > 1 ? min : 1);
      }
      if (/code|reference|numero|imei|serie/.test(n)) return 'E2E' + u;
      if (/adresse|quartier|commune|ville/.test(n)) return 'Kaloum, Conakry';
      if (el.type === 'password') return null;    // jamais de mot de passe
      if (el.type === 'search') return 'a';
      return 'Essai E2E ' + u;
    };
    let rempli = 0;
    for (const el of f.querySelectorAll('input, select, textarea')) {
      if (el.disabled || el.readOnly || el.type === 'hidden' || el.type === 'file' || el.type === 'checkbox' || el.type === 'radio' || el.type === 'submit') continue;
      if (el.offsetParent === null && el.type !== 'date') continue;   // champ caché (bloc replié, autre mode)
      if (el.tagName === 'SELECT') {
        if (!el.value && el.options.length > 1) { el.selectedIndex = [...el.options].findIndex((o, i) => i > 0 && o.value) ; rempli++; }
        el.dispatchEvent(new Event('change', { bubbles: true }));
        continue;
      }
      if (el.value && el.type !== 'search') continue;   // garder ce qui est déjà prérempli
      const v = val(el);
      if (v === null) continue;
      el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); rempli++;
    }
    return rempli;
  }, unique());
}

(async () => {
  const navigateur = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), ...(process.env.E2E_VISIBLE ? { headless: false, slowMo: Number(process.env.E2E_VISIBLE) > 1 ? Number(process.env.E2E_VISIBLE) : 250, args: ['--start-maximized'] } : {}) });
  const contexte = await navigateur.newContext({ viewport: { width: 1280, height: 800 }, locale: 'fr-FR' });
  const page = await contexte.newPage();
  page.setDefaultTimeout(20000);   // connexion et premier chargement : le serveur compile les vues
  let erreursJs = [];
  let erreursServeur = [];
  page.on('pageerror', (e) => erreursJs.push(String(e.message).slice(0, 140)));
  page.on('response', (r) => { if (r.status() >= 500) erreursServeur.push(r.status() + ' ' + r.url().replace(BASE, '')); });

  const urls = new Set();
  const ajouter = (href) => {
    if (!href) return;
    const u = new URL(href, BASE);
    if (u.origin === BASE && !PAGES_EXCLUES.test(u.pathname + u.search) && !u.hash) urls.add(u.pathname + u.search);
  };
  if (MODE === 'public') {
    // Sans connexion : pages publiques et vitrine de la boutique de démonstration
    for (const u of ['/', '/connexion', '/mot-de-passe-oublie', '/creer-ma-boutique', '/vitrine/' + (process.env.E2E_VITRINE || 'boutique-demo-kindia')]) urls.add(u);
  } else {
    await page.goto(BASE + '/connexion');
    await page.fill('#email', process.env.E2E_EMAIL || 'demo@gngestion.com');
    await page.fill('#password', process.env.E2E_MDP || 'demo1234');
    await Promise.all([page.waitForURL(/tableau-de-bord|\/admin/, { timeout: 20000 }), page.click('button:has-text("Se connecter")')]);
  }

  page.setDefaultTimeout(4000);
  if (MODE !== 'public') {
    // Toutes les pages : menu, raccourcis, premières fiches de chaque liste
    for (const h of await page.$$eval('aside a[href]', as => as.map(a => a.href))) ajouter(h);
    for (const u of [...urls]) {
      await page.goto(BASE + u);
      (await page.$$eval('tbody tr a[href]', as => [...new Set(as.map(a => a.href))].slice(0, 2)).catch(() => [])).forEach(ajouter);
    }
    if (MODE === 'boutique') ['/aide', '/caisse', '/approvisionnements/create', '/produits/nouveau', '/clients/nouveau', '/garanties?q=356', '/stock/inventaire', '/notifications', '/profil', '/cartes-cadeaux'].forEach(ajouter);
  }

  const verifier = (ou, quoi) => {
    if (erreursJs.length) anomalies.push(`${ou} — ${quoi} : erreur JavaScript : ${[...new Set(erreursJs)].join(' | ')}`);
    if (erreursServeur.length) anomalies.push(`${ou} — ${quoi} : erreur serveur ${[...new Set(erreursServeur)].join(', ')}`);
    erreursJs = []; erreursServeur = [];
  };

  if (process.env.E2E_PAGES) { urls.clear(); process.env.E2E_PAGES.split(',').forEach((x) => urls.add(x.trim())); }
  // « Mes boutiques » en dernier : créer un point de vente fait travailler dans la nouvelle boutique (vide)
  for (const u of [...urls].sort((a, b) => (a.startsWith('/mes-boutiques') - b.startsWith('/mes-boutiques')) || a.localeCompare(b))) {
    stats.pages++;
    try {
    // ---------- 1. Tous les éléments cliquables qui restent sur la page ----------
    await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
    verifier(u, 'ouverture');
    const nbClic = await page.locator('button[type=button]:visible, summary:visible, [data-bs-toggle]:visible, .nav-link[role=tab]:visible, a[href="#"]:visible').count();
    for (let i = 0; i < Math.min(nbClic, 40); i++) {
      const el = page.locator('button[type=button]:visible, summary:visible, [data-bs-toggle]:visible, .nav-link[role=tab]:visible, a[href="#"]:visible').nth(i);
      const libelle = ((await el.getAttribute('aria-label').catch(() => null)) || (await el.innerText().catch(() => '')) || (await el.getAttribute('title').catch(() => '')) || '?').trim().slice(0, 40);
      if (/déconnecter|écran client|plein écran|imprimer|caméra|scanner/i.test(libelle)) continue;
      try {
        await el.click({ timeout: 2500 });
        stats.clics++;
        await page.waitForTimeout(250);
        // Confirmation : on répond « non » ; fenêtre ouverte : on la ferme
        if (await page.locator('[data-oui]:visible').count()) await page.keyboard.press('Escape');
        if (await page.locator('.modal.show').count()) await page.keyboard.press('Escape');
        await page.waitForTimeout(150);
        if (!page.url().startsWith(BASE + u.split('?')[0])) await page.goto(BASE + u, { waitUntil: 'networkidle' });
      } catch (e) { /* élément devenu invisible après un autre clic : normal */ }
      verifier(u, `clic « ${libelle} »`);
    }

    // ---------- 1 bis. Tous les liens internes de la page (tri, pagination, segments, fiches liées) ----------
    await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
    const liens = await page.$$eval('main a[href]', (as) => as.map((a) => a.href)).catch(() => []);
    for (const href of liens) {
      const l = new URL(href, BASE);
      if (l.origin !== BASE || PAGES_EXCLUES.test(l.pathname + l.search) || liensVus.has(l.pathname + l.search)) continue;
      liensVus.add(l.pathname + l.search);
      const r = await page.request.get(l.href).catch(() => null);
      stats.liens++;
      const corps = r ? await r.text().catch(() => '') : '';
      if (!r || r.status() >= 400) anomalies.push(`${u} — lien ${l.pathname + l.search} : statut ${r ? r.status() : 'aucune réponse'}`);
      else if (/Whoops|Server Error|Undefined (variable|array key)|SQLSTATE|ErrorException/.test(corps)) anomalies.push(`${u} — lien ${l.pathname + l.search} : erreur technique affichée`);
    }

    // ---------- 1 ter. Listes de filtres qui s'appliquent au changement ----------
    const nbFiltres = await page.locator('main form[method=get] select, main form:not([method]) select').count();
    for (let i = 0; i < Math.min(nbFiltres, 6); i++) {
      await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
      const sel = page.locator('main form[method=get] select, main form:not([method]) select').nth(i);
      if (!(await sel.isVisible().catch(() => false))) continue;
      const nbOptions = await sel.locator('option').count();
      for (let k = 1; k < Math.min(nbOptions, 4); k++) {
        await sel.selectOption({ index: k }).catch(() => {});
        await page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
        stats.filtres++;
        if (/Whoops|Server Error|SQLSTATE|ErrorException/.test(await page.locator('body').innerText().catch(() => ''))) anomalies.push(`${u} — filtre n°${i + 1}, choix ${k} : erreur technique`);
        verifier(u, `filtre n°${i + 1}, choix ${k}`);
        if (!page.url().startsWith(BASE + u.split('?')[0])) break;
      }
    }

    // ---------- 2. Tous les formulaires (remplis puis envoyés) ----------
    await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
    const zone = MODE === 'public' ? 'form' : 'main form';
    const nbForm = await page.locator(zone).count();
    for (let i = 0; i < Math.min(nbForm, 12); i++) {
      await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => {});
      const form = page.locator(zone).nth(i);
      const action = (await form.getAttribute('action').catch(() => '')) || u;
      const methodeCachee = await form.locator('input[name=_method]').getAttribute('value').catch(() => null);
      if (FORMULAIRES_EXCLUS.test(action) || /delete/i.test(methodeCachee || '') || (MODE === 'public' && /connexion$/.test(action))) continue;
      const bouton = form.locator('button:not([type=button]), input[type=submit]').first();
      if (!(await bouton.count()) || !(await bouton.isVisible().catch(() => false)) || await bouton.isDisabled().catch(() => true)) continue;
      // Ouvrir les blocs repliables qui contiennent le formulaire
      await form.evaluate((f) => { let p = f.closest('details'); while (p) { p.open = true; p = p.parentElement.closest('details'); } }).catch(() => {});
      await remplir(page, form).catch(() => 0);
      const libelle = ((await bouton.innerText().catch(() => '')) || 'envoyer').trim().replace(/\s+/g, ' ').slice(0, 40);
      const avant = page.url();
      stats.formulaires++;
      try {
        // Attendre la page renvoyée par le serveur (sinon on lirait l'ancienne page et un succès passerait pour « rien »)
        const navigation = page.waitForNavigation({ timeout: 8000 }).catch(() => null);
        await bouton.click({ timeout: 2500 });
        const oui = page.locator('[data-oui]');
        if (await oui.waitFor({ state: 'visible', timeout: 1500 }).then(() => true).catch(() => false)) await oui.click();
        await navigation;
        await page.waitForLoadState('load', { timeout: 10000 }).catch(() => {});
      } catch (e) { /* bouton masqué par la validation du navigateur : refus contrôlé */ }
      const champsRefuses = await page.evaluate(() => [...document.querySelectorAll('main input:invalid, main select:invalid, main textarea:invalid')]
        .filter((e) => e.offsetParent).map((e) => `${e.name || e.id} (${e.validationMessage})`).slice(0, 4)).catch(() => []);
      const etat = await page.evaluate(() => ({
        succes: !!document.querySelector('.alert-success, .alert-info'),
        refus: !!document.querySelector('.alert-danger, .invalid-feedback, .is-invalid, :invalid'),
        crash: /Whoops|Server Error|Undefined (variable|array key)|SQLSTATE|ErrorException|Call to a member/.test(document.body.innerText),
      })).catch(() => ({}));
      journal.push(`${etat.crash ? 'ERREUR' : etat.succes ? 'succès' : etat.refus ? 'refus ' : 'rien  '} | ${u} | « ${libelle} » → ${action.replace(BASE, '')} | arrivé sur ${page.url().replace(BASE, '')}${champsRefuses.length ? ' | champs refusés : ' + champsRefuses.join(', ') : ''}`);
      if (etat.crash) anomalies.push(`${u} — formulaire « ${libelle} » (${action.replace(BASE, '')}) : la page affiche une erreur technique`);
      else if (etat.succes) stats.succes++;
      else if (etat.refus) stats.refus++;
      else stats.sansEffet++;
      verifier(u, `formulaire « ${libelle} » (${action.replace(BASE, '')})`);
      if (page.url() !== avant && /\/(tableau-de-bord|connexion)$/.test(page.url()) && !/tableau-de-bord/.test(u) && !etat.succes) {
        anomalies.push(`${u} — formulaire « ${libelle} » : renvoyé vers ${page.url().replace(BASE, '')} (session perdue ?)`);
      }
    }
    } catch (e) { anomalies.push(`${u} — arrêt inattendu : ${String(e.message).split('\n')[0]}`); }
  }

  // ---------- 3. Téléphone : menu et éléments de la caisse ----------
  await page.setViewportSize({ width: 360, height: 780 });
  const pagesTelephone = { boutique: ['/tableau-de-bord', '/caisse', '/ventes', '/clients', '/cartes-cadeaux'], admin: ['/admin', '/admin/boutiques'], public: [] }[MODE];
  for (const u of pagesTelephone) {
    await page.goto(BASE + u, { waitUntil: 'networkidle' }).catch(() => page.goto(BASE + u).catch(() => anomalies.push(`${u} (téléphone) : la page ne se charge pas`)));
    await page.click('[data-ouvrir-menu]').catch(() => anomalies.push(`${u} (téléphone) : bouton du menu introuvable`));
    await page.keyboard.press('Escape');
    const deborde = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    if (deborde > 1) anomalies.push(`${u} (téléphone) : la page déborde de ${deborde}px`);
    verifier(u, 'téléphone');
  }

  console.log(`Pages : ${stats.pages} | clics : ${stats.clics} | liens ouverts : ${stats.liens} | choix de filtres : ${stats.filtres} | formulaires envoyés : ${stats.formulaires} `
    + `(succès ${stats.succes}, refus contrôlés ${stats.refus}, sans message ${stats.sansEffet})`);
  console.log(anomalies.length ? `ANOMALIES (${anomalies.length}) :\n- ` + [...new Set(anomalies)].join('\n- ') : 'Aucune anomalie.');
  fs.writeFileSync(path.join(OUT, `rapport-${MODE}${process.env.E2E_TOUT ? '-tout' : ''}.json`), JSON.stringify({ stats, anomalies, journal }, null, 1));
  if (process.env.E2E_DETAIL) console.log(journal.join('\n'));
  await navigateur.close();
})().catch((e) => { console.error('ÉCHEC : ' + e.message.split('\n')[0]); process.exit(1); });
