// À lancer UNIQUEMENT sur la copie de démonstration isolée (port 8012) : ce script enregistre des ventes et des retours.
// Échange au comptoir, sur téléphone puis sur ordinateur : vente de 2 sacs, retour d'un sac en « Échange »,
// la caisse s'ouvre avec le bon, on prend un article moins cher et la caisse annonce le reste à rendre.
const { chromium, expect } = require(process.env.PLAYWRIGHT_TEST || '@playwright/test');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8012';
if (!(new URL(BASE).port === '8012') && process.env.E2E_COPIE_DE_TEST !== 'oui') {
  console.error('Refusé : ' + BASE + " n'est pas la copie de test (port 8012).");
  process.exit(1);
}

async function confirmer(page) {
  const oui = page.locator('[data-oui]');
  await oui.waitFor({ state: 'visible', timeout: 5000 });
  await oui.click();
}

(async () => {
  const navigateur = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), ...(process.env.E2E_VISIBLE ? { headless: false, slowMo: Number(process.env.E2E_VISIBLE) > 1 ? Number(process.env.E2E_VISIBLE) : 250, args: ['--start-maximized'] } : {}) });
  let echecs = 0;
  for (const largeur of [390, 1280]) {
    const contexte = await navigateur.newContext({ viewport: { width: largeur, height: 860 }, ...(process.env.E2E_VIDEO ? { recordVideo: { dir: __dirname + '/videos' } } : {}) });
    const page = await contexte.newPage();
    page.setDefaultTimeout(10000);
    const erreursJs = [];
    page.on('pageerror', (e) => erreursJs.push(e.message));
    try {
      await page.goto(BASE + '/connexion');
      await page.fill('#email', 'demo@gngestion.com');
      await page.fill('#password', 'demo1234');
      await Promise.all([page.waitForURL(/tableau-de-bord/), page.click('button:has-text("Se connecter")')]);

      // 1. Vente comptoir de 2 sacs de riz, payée en espèces
      await page.goto(BASE + '/caisse');
      await page.fill('#recherche', 'Riz');
      const riz = page.locator('button.produit-tuile', { hasText: 'Riz' }).first();
      await riz.click();
      await riz.click();
      await page.click('#valider');
      await confirmer(page).catch(() => {});
      await page.waitForURL(/\/ventes\/\d+/);
      const urlVente = page.url().split('?')[0];

      // 2. Retour d'un sac en « Échange »
      await page.goto(urlVente);
      await page.locator('summary:has-text("retour de marchandise")').click();
      await page.locator('input[name^="quantites["]').first().fill('1');
      await page.selectOption('#motif_retour', { index: 1 });
      await page.selectOption('#mode_remboursement', 'echange');
      await page.locator('button:has-text("Enregistrer le retour")').click();
      await confirmer(page).catch(() => {});
      await page.waitForURL(/\/caisse\?.*echange=\d+/);
      await expect(page.locator('#blocEchange')).toContainText('Échange RET');
      const bandeau = (await page.locator('#blocEchange').innerText()).replace(/\s+/g, ' ');

      // 3. Le client prend un article moins cher : la caisse annonce le reste rendu en espèces
      await page.fill('#recherche', 'Sucre');
      await page.locator('button.produit-tuile', { hasText: 'Sucre' }).first().click();
      await expect(page.locator('#etatEchange')).toContainText('Payé avec le bon');
      const etat = (await page.locator('#etatEchange').innerText()).replace(/\s+/g, ' ');
      if (!/Reste du bon rendu en espèces/.test(etat)) throw new Error('reste à rendre non affiché : ' + etat);
      const deborde = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
      await page.screenshot({ path: __dirname + `/../../storage/logs/e2e-echange-caisse-${largeur}.png`, fullPage: true });
      await page.click('#valider');
      await confirmer(page).catch(() => {});
      await page.waitForURL(/\/ventes\/\d+/);
      const message = (await page.locator('.alert-success').first().innerText()).replace(/\s+/g, ' ');
      if (!/Échange RET-?\S* effectué/.test(message) || !/Rendez .* au client/.test(message)) throw new Error('message final inattendu : ' + message);

      // 4. La vente d'origine renvoie vers l'échange
      await page.goto(urlVente);
      await expect(page.locator('main')).toContainText('utilisé sur la vente');
      console.log(`${largeur}px OK | ${bandeau.slice(0, 70)} | ${etat} | ${message.slice(0, 120)} | débordement ${deborde}px | erreurs JS ${erreursJs.length}`);
    } catch (e) {
      echecs++;
      console.log(`${largeur}px ÉCHEC : ${e.message.split('\n')[0]}` + (erreursJs.length ? ' ; ERREURS JS : ' + erreursJs.join(' | ') : ''));
      await page.screenshot({ path: __dirname + `/../../storage/logs/e2e-echange-echec-${largeur}.png`, fullPage: true }).catch(() => {});
    }
    await contexte.close();
  }
  await navigateur.close();
  process.exit(echecs ? 1 : 0);
})().catch((e) => { console.error('ÉCHEC : ' + e.message.split('\n')[0]); process.exit(1); });
