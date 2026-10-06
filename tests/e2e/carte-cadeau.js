// À lancer UNIQUEMENT sur la copie de démonstration isolée (port 8012) : ce script vend des cartes cadeaux et enregistre des ventes.
// Vérifie dans le navigateur, sur téléphone et sur ordinateur : vente d'une carte, carte imprimable, paiement à la caisse
// avec le code (vérification, reste à payer), refus d'un code inconnu, solde mis à jour, et aucun débordement horizontal.
const { chromium, expect } = require(process.env.PLAYWRIGHT_TEST || '@playwright/test');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8012';
if (!(new URL(BASE).port === '8012') && process.env.E2E_COPIE_DE_TEST !== 'oui') {
  console.error('Refusé : ' + BASE + " n'est pas la copie de test (port 8012).");
  process.exit(1);
}
const gnf = (t) => Number(String(t).replace(/\D/g, ''));

async function confirmer(page) {
  const oui = page.locator('[data-oui]');
  await oui.waitFor({ state: 'visible', timeout: 5000 });
  await oui.click();
}

async function deborde(page) {
  return page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
}

async function parcours(navigateur, largeur) {
  const contexte = await navigateur.newContext({ viewport: { width: largeur, height: 860 } });
  const page = await contexte.newPage();
  page.setDefaultTimeout(10000);
  const erreursJs = [];
  page.on('pageerror', (e) => erreursJs.push(e.message));
  const journal = [];
  await page.goto(BASE + '/connexion');
  await page.fill('#email', 'demo@gngestion.com');
  await page.fill('#password', 'demo1234');
  await Promise.all([page.waitForURL(/tableau-de-bord/), page.click('button:has-text("Se connecter")')]);

  // 1. Vendre une carte de 100 000 (bouton de montant rapide)
  await page.goto(BASE + '/cartes-cadeaux');
  journal.push(`liste ${await deborde(page)}px`);
  await page.locator('.montant-rapide', { hasText: '100' }).click();
  await expect(page.locator('#montant')).toHaveValue(/100/);
  await page.fill('#beneficiaire', 'Fatoumata Camara');
  await page.fill('input[name=message]', 'Joyeux anniversaire !');
  await page.locator('#formCarte button:has-text("Encaisser la carte")').click();
  await confirmer(page);
  await page.waitForURL(/\/cartes-cadeaux\/\d+$/);
  await expect(page.locator('.alert').first()).toContainText('Carte cadeau de 100 000');
  journal.push(`fiche ${await deborde(page)}px`);
  const urlFiche = page.url();

  // 2. Carte imprimable : code complet, bénéficiaire, message
  const carte = await page.context().newPage();   // même session (connecté)
  await carte.goto(urlFiche + '/imprimer');
  const code = (await carte.locator('.code').innerText()).trim();
  if (!/^CC-[2-9A-Z]{4}-[2-9A-Z]{4}$/.test(code)) throw new Error('code illisible sur la carte : ' + code);
  await expect(carte.locator('body')).toContainText('Fatoumata Camara');
  await expect(carte.locator('body')).toContainText('Joyeux anniversaire');
  await carte.screenshot({ path: __dirname + `/../../storage/logs/e2e-carte-cadeau-${largeur}.png`, fullPage: true });
  journal.push(`carte ${code} ${await deborde(carte)}px`);
  await carte.close();

  // 3. Caisse : un produit, code inconnu refusé, puis le bon code
  await page.goto(BASE + '/caisse');
  const tuile = page.locator('button.produit-tuile:not([disabled])').first();   // premier produit en stock
  await tuile.waitFor();
  await tuile.click();
  await tuile.click();
  const total = gnf(await page.locator('#total').innerText());
  if (largeur < 992 && !(await page.locator('#ouvrirCarte').isVisible())) {
    await page.locator('.total-mobile button, .total-mobile').first().click();   // ticket replié sur téléphone
  }
  await page.locator('#ouvrirCarte').click();
  await page.fill('#carte_cadeau', 'CC-AAAA-AAAA');
  await page.click('#verifierCarte');
  await expect(page.locator('#etatCarte')).toContainText('introuvable');
  await page.fill('#carte_cadeau', code.toLowerCase().replace(/-/g, ' '));
  await page.click('#verifierCarte');
  await expect(page.locator('#etatCarte')).toContainText('Solde disponible : 100 000');
  await expect(page.locator('#etatCarte')).toContainText('Payé avec la carte');
  await expect(page.locator('#carte_cadeau')).toHaveValue(code);
  const placeholder = await page.locator('#montant_recu').getAttribute('placeholder');
  if (gnf(placeholder) !== Math.max(0, total - 100000)) throw new Error(`reste à payer ${placeholder}, attendu ${total - 100000}`);
  journal.push(`caisse ${await deborde(page)}px`);
  await page.selectOption('#mode', 'especes');
  await page.click('#valider');
  await confirmer(page).catch(() => {});
  await page.waitForURL(/\/ventes\/\d+/);
  await expect(page.locator('.alert').first()).toContainText('enregistrée');
  await expect(page.locator('body')).toContainText('Carte cadeau');

  // 4. La carte est épuisée : solde 0, et la caisse la refuse
  await page.goto(urlFiche);
  await expect(page.locator('body')).toContainText('Dépensée');
  await expect(page.locator('body')).toContainText('Utilisée');
  await page.goto(BASE + '/caisse');
  await page.locator('button.produit-tuile:not([disabled])').first().click();
  if (largeur < 992 && !(await page.locator('#ouvrirCarte').isVisible())) {
    await page.locator('.total-mobile button, .total-mobile').first().click();
  }
  await page.locator('#ouvrirCarte').click();
  await page.fill('#carte_cadeau', code);
  await page.click('#verifierCarte');
  await expect(page.locator('#etatCarte')).toContainText('entièrement utilisée');
  await page.click('#retirerCarte');
  await expect(page.locator('#ouvrirCarte')).toBeVisible();
  await page.click('#vider').catch(() => {});
  await confirmer(page).catch(() => {});

  await page.screenshot({ path: __dirname + `/../../storage/logs/e2e-caisse-carte-${largeur}.png` });
  await contexte.close();
  return `${largeur}px : ${journal.join(' · ')} ; vente payée par carte (${total} GNF), carte épuisée refusée`
    + (erreursJs.length ? ' ; ERREURS JS : ' + erreursJs.join(' | ') : ' ; aucune erreur JS');
}

(async () => {
  const navigateur = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), ...(process.env.E2E_VISIBLE ? { headless: false, slowMo: Number(process.env.E2E_VISIBLE) > 1 ? Number(process.env.E2E_VISIBLE) : 250, args: ['--start-maximized'] } : {}) });
  for (const largeur of [390, 1100]) {
    console.log('OK ' + await parcours(navigateur, largeur));
  }
  await navigateur.close();
})().catch((e) => { console.error('ÉCHEC : ' + e.message.split('\n').slice(0, 4).join(' ')); process.exit(1); });
