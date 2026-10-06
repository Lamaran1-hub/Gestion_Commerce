// À lancer UNIQUEMENT sur la copie de démonstration isolée (base SQLite séparée, port 8012) : ce script se connecte avec le compte de démonstration.
// Démarrer la copie :  DB_CONNECTION=sqlite DB_DATABASE=<copie>.sqlite MAIL_MAILER=log php artisan serve --port=8012
// Puis :              node tests/e2e/tour.js
// Tour complet de l'application (copie de démonstration isolée, port 8012) avec Playwright.
// Pour chaque page : statut HTTP, erreurs JavaScript/console, débordement horizontal, message d'erreur Laravel ; capture d'écran.
const { chromium } = require(process.env.PLAYWRIGHT || 'playwright');
const fs = require('fs');
const path = require('path');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8012';   // copie de démonstration isolée, jamais la vraie base
if (!(new URL(BASE).port === '8012') && process.env.E2E_COPIE_DE_TEST !== 'oui') {
  console.error('Refusé : ' + BASE + " n'est pas la copie de test (port 8012). Ces essais modifieraient de vraies données.");
  process.exit(1);
}
const OUT = path.join(__dirname, 'captures');
fs.mkdirSync(OUT, { recursive: true });
const TAILLES = { telephone: { width: 360, height: 780 }, tablette: { width: 768, height: 1024 }, ordinateur: { width: 1280, height: 800 } };
// Jamais : déconnexion, suppression, actions externes
const EXCLUS = /deconnexion|logout|supprimer|destroy|wa\.me|tel:|mailto:|\/export|\.pdf|\/proforma|\/facture|\/bon|\/recu|bon-avoir|releve|ecran-client|telecharger|sauvegarde/i;

(async () => {
  const navigateur = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), ...(process.env.E2E_VISIBLE ? { headless: false, slowMo: Number(process.env.E2E_VISIBLE) > 1 ? Number(process.env.E2E_VISIBLE) : 250, args: ['--start-maximized'] } : {}) });
  const contexte = await navigateur.newContext({ viewport: TAILLES.ordinateur, locale: 'fr-FR' });
  const page = await contexte.newPage();

  // Connexion (compte de démonstration de la copie isolée)
  await page.goto(BASE + '/connexion');
  await page.fill('input[name=email]', 'demo@gngestion.com');
  await page.fill('input[name=password]', 'demo1234');
  await Promise.all([page.waitForURL(u => !String(u).includes('/connexion'), { timeout: 20000 }), page.click('button:has-text("Se connecter")')]);
  if (page.url().includes('connexion')) { console.log('ÉCHEC CONNEXION'); process.exit(1); }

  // Pages du menu + pages de détail trouvées dans les listes
  const urls = new Set();
  const ajouter = (href) => {
    if (!href) return;
    const u = new URL(href, BASE);
    if (u.origin !== BASE || EXCLUS.test(u.pathname + u.search) || u.hash) return;
    urls.add(u.pathname + u.search);
  };
  await page.goto(BASE + '/tableau-de-bord');
  for (const h of await page.$$eval('aside a[href], .raccourcis a[href]', as => as.map(a => a.href))) ajouter(h);
  const listes = [...urls];
  for (const u of listes) {
    await page.goto(BASE + u);
    // premier lien de détail de chaque liste (fiche client, vente, devis, réception…)
    const details = await page.$$eval('main tbody tr a[href], .bloc tbody tr a[href]', as => [...new Set(as.map(a => a.href))].slice(0, 2)).catch(() => []);
    details.forEach(ajouter);
  }
  ['/aide', '/profil', '/parametres', '/notifications', '/caisse', '/approvisionnements/create', '/produits/nouveau', '/clients/nouveau',
   '/garanties?q=789012', '/livraisons?vue=livrees', '/commandes-fournisseur?vue=toutes', '/stock/inventaire'].forEach(ajouter);

  const resultats = [];
  for (const [nomTaille, taille] of Object.entries(TAILLES)) {
    await page.setViewportSize(taille);
    for (const u of [...urls].sort()) {
      const erreurs = [];
      const surErreur = (e) => erreurs.push('JS: ' + String(e.message || e).slice(0, 160));
      const surConsole = (m) => { if (m.type() === 'error' && !/favicon|Dark Reader/i.test(m.text())) erreurs.push('console: ' + m.text().slice(0, 160)); };
      page.on('pageerror', surErreur); page.on('console', surConsole);
      let statut = 0;
      try {
        const r = await page.goto(BASE + u, { waitUntil: 'networkidle', timeout: 20000 });
        statut = r ? r.status() : 0;
      } catch (e) { erreurs.push('chargement: ' + e.message.slice(0, 120)); }
      const mesure = await page.evaluate(() => {
        const trop = document.documentElement.scrollWidth - window.innerWidth;
        // éléments qui dépassent l'écran (hors zones prévues pour défiler)
        const coupables = [...document.querySelectorAll('body *')].filter(e => {
          const r = e.getBoundingClientRect();
          return r.right > window.innerWidth + 1 && r.width > 0 && !e.closest('.table-responsive, [style*="overflow"], .overflow-auto, .offcanvas, aside, .voile-menu, .dropdown-menu, .modal');
        }).slice(0, 3).map(e => e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + '.' + [...e.classList].slice(0, 2).join('.'));
        const texte = document.body.innerText;
        return { trop, coupables, erreurLaravel: /Whoops|Server Error|Internal Server Error|Undefined (variable|array key)|SQLSTATE|ErrorException/.test(texte), titre: document.title };
      }).catch(() => ({ trop: 0, coupables: [], erreurLaravel: false, titre: '?' }));
      page.off('pageerror', surErreur); page.off('console', surConsole);
      const nomFichier = (nomTaille + u.replace(/[^a-z0-9]+/gi, '_')).slice(0, 120) + '.png';
      if (nomTaille !== 'tablette') await page.screenshot({ path: path.join(OUT, nomFichier), fullPage: false }).catch(() => {});
      const probleme = statut >= 400 || erreurs.length || mesure.erreurLaravel || mesure.trop > 1;
      resultats.push({ taille: nomTaille, url: u, statut, debordement: mesure.trop, coupables: mesure.coupables, erreurLaravel: mesure.erreurLaravel, erreurs, probleme });
    }
  }
  fs.writeFileSync(path.join(__dirname, 'resultats.json'), JSON.stringify(resultats, null, 1));
  const pb = resultats.filter(r => r.probleme);
  console.log(`Pages testées : ${urls.size} × 3 tailles = ${resultats.length} vérifications ; problèmes : ${pb.length}`);
  for (const r of pb) console.log(`- [${r.taille}] ${r.url} statut=${r.statut} débordement=${r.debordement}px ${r.coupables.join(' ')} ${r.erreurLaravel ? 'ERREUR-LARAVEL' : ''} ${r.erreurs.join(' | ')}`);
  await navigateur.close();
})();
