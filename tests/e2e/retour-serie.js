// À lancer UNIQUEMENT sur la copie de démonstration isolée (port 8012) : ce script enregistre un retour.
// Vérifie dans le navigateur : cocher l'IMEI rapporté remplit la quantité, puis le retour marque le numéro « repris ».
const { chromium, expect } = require(process.env.PLAYWRIGHT_TEST || '@playwright/test');
const BASE = process.env.BASE_URL || 'http://127.0.0.1:8012';
if (!(new URL(BASE).port === '8012') && process.env.E2E_COPIE_DE_TEST !== 'oui') {
  console.error('Refusé : ' + BASE + " n'est pas la copie de test (port 8012).");
  process.exit(1);
}

(async () => {
  const navigateur = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), ...(process.env.E2E_VISIBLE ? { headless: false, slowMo: Number(process.env.E2E_VISIBLE) > 1 ? Number(process.env.E2E_VISIBLE) : 250, args: ['--start-maximized'] } : {}) });
  const page = await navigateur.newPage({ viewport: { width: 360, height: 780 } });
  page.setDefaultTimeout(10000);
  const erreursJs = [];
  page.on('pageerror', (e) => erreursJs.push(e.message));
  await page.goto(BASE + '/connexion');
  await page.fill('#email', 'demo@gngestion.com');
  await page.fill('#password', 'demo1234');
  await Promise.all([page.waitForURL(/tableau-de-bord/), page.click('button:has-text("Se connecter")')]);

  // Une vente de téléphones avec IMEI (garanties) : on cherche la dernière
  await page.goto(BASE + '/garanties?q=3561234567890');
  const lien = page.locator('tbody a[href*="/ventes/"]').first();
  const url = await lien.getAttribute('href');
  await page.goto(url.replace(/#.*/, ''));
  await page.locator('summary:has-text("retour de marchandise")').click();
  const cases = page.locator('.series-retour input[type=checkbox]');
  const nb = await cases.count();
  if (!nb) throw new Error('aucune case de numéro de série dans le formulaire de retour');
  await cases.first().check();
  const qte = await page.locator('.series-retour').first().evaluate(b => document.getElementById('qteRetour' + b.dataset.ligne).value);
  if (qte !== '1') throw new Error('la quantité ne suit pas la case cochée : ' + qte);
  await page.selectOption('#motif_retour', { index: 1 });
  await page.locator('button:has-text("Enregistrer le retour")').click();
  await page.locator('[data-oui]').click();
  await page.waitForLoadState('load');
  await expect(page.locator('.alert').first()).toContainText('Retour');
  await expect(page.locator('#series')).toContainText('Rapportés');
  const deborde = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
  await page.screenshot({ path: __dirname + '/../../storage/logs/e2e-retour-serie.png', fullPage: true });
  console.log(`OK : ${nb} numéro(s) proposés, quantité remplie en cochant, retour enregistré et numéro marqué « rapporté » ; débordement ${deborde}px`
    + (erreursJs.length ? ' ; ERREURS JS : ' + erreursJs.join(' | ') : ''));
  await navigateur.close();
})().catch((e) => { console.error('ÉCHEC : ' + e.message.split('\n')[0]); process.exit(1); });
