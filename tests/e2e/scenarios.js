// À lancer UNIQUEMENT sur la copie de démonstration isolée (base SQLite séparée, port 8012) : ce script crée des ventes, dépenses, clients.
// Démarrer la copie :  DB_CONNECTION=sqlite DB_DATABASE=<copie>.sqlite MAIL_MAILER=log php artisan serve --port=8012
// Puis :              node tests/e2e/scenarios.js
// Scénarios d'utilisation réels (copie de démonstration isolée) : chaque composant est manipulé comme un utilisateur le ferait.
const { chromium, expect } = require(process.env.PLAYWRIGHT_TEST || '@playwright/test');
const path = require('path');
const fs = require('fs');

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8012';   // copie de démonstration isolée, jamais la vraie base
if (!(new URL(BASE).port === '8012') && process.env.E2E_COPIE_DE_TEST !== 'oui') {
  console.error('Refusé : ' + BASE + " n'est pas la copie de test (port 8012). Ces essais modifieraient de vraies données.");
  process.exit(1);
}
const OUT = path.join(__dirname, 'scenarios');
fs.mkdirSync(OUT, { recursive: true });
const bilan = [];
// E2E_VIDEO=1 : chaque scénario est filmé (tests/e2e/videos/), pour regarder les tests après coup
const VIDEOS = path.join(__dirname, 'videos');
const video = (largeur, hauteur) => (process.env.E2E_VIDEO ? { recordVideo: { dir: VIDEOS, size: { width: largeur, height: hauteur } } } : {});

async function scenario(nom, page, fn) {
  const erreursJs = [];
  const surErreur = (e) => erreursJs.push(String(e.message || e).slice(0, 150));
  page.on('pageerror', surErreur);
  try {
    const detail = await fn();
    if (erreursJs.length) throw new Error('erreur JavaScript : ' + erreursJs.join(' | '));
    bilan.push({ nom, ok: true, detail: detail || '' });
  } catch (e) {
    await page.screenshot({ path: path.join(OUT, 'ECHEC_' + nom.replace(/[^a-z0-9]+/gi, '_') + '.png'), fullPage: true }).catch(() => {});
    bilan.push({ nom, ok: false, detail: String(e.message).split('\n').slice(0, 4).join(' ') });
  }
  page.off('pageerror', surErreur);
}

/** Répond « Oui » à la fenêtre de confirmation de l'application. */
async function confirmer(page) {
  const oui = page.locator('[data-oui]');
  await oui.waitFor({ state: 'visible', timeout: 5000 });
  await oui.click();
}

(async () => {
  const navigateur = await chromium.launch({ ...(process.env.CHROME_PATH ? { executablePath: process.env.CHROME_PATH } : {}), ...(process.env.E2E_VISIBLE ? { headless: false, slowMo: Number(process.env.E2E_VISIBLE) > 1 ? Number(process.env.E2E_VISIBLE) : 250, args: ['--start-maximized'] } : {}) });
  if (process.env.E2E_VIDEO) fs.rmSync(VIDEOS, { recursive: true, force: true });
  const contexte = await navigateur.newContext({ viewport: { width: 1280, height: 800 }, locale: 'fr-FR', ...video(1280, 800) });
  const page = await contexte.newPage();
  page.setDefaultTimeout(10000);

  await scenario('Connexion : mauvais mot de passe refusé, puis connexion', page, async () => {
    await page.goto(BASE + '/connexion');
    await page.fill('#email', 'demo@gngestion.com');
    await page.fill('#password', 'faux-mot-de-passe');
    await page.click('button:has-text("Se connecter")');
    await expect(page.locator('body')).toContainText(/incorrect|invalide|ne correspond/i);
    await page.fill('#password', 'demo1234');
    await page.click('[aria-label*="mot de passe" i], .bi-eye, .bi-eye-slash').catch(() => {});
    await Promise.all([page.waitForURL(/tableau-de-bord/), page.click('button:has-text("Se connecter")')]);
    return 'refus puis accès au tableau de bord';
  });

  await scenario('Centre d\'aide : recherche', page, async () => {
    await page.goto(BASE + '/aide');
    const champ = page.locator('input[type=search], input[name=q]').first();
    await champ.fill('garantie');
    await champ.press('Enter').catch(() => {});
    await expect(page.locator('body')).toContainText('Garantie et numéros de série');
    return 'guide trouvé';
  });

  await scenario('Caisse : recherche, ticket, remise, monnaie, vente', page, async () => {
    await page.goto(BASE + '/caisse');
    await page.fill('#recherche', 'Riz');
    const tuile = page.locator('button.produit-tuile', { hasText: 'Riz' }).first();
    await tuile.waitFor();
    await tuile.click();
    await tuile.click();                                   // 2 sacs
    await expect(page.locator('#lignes input[type=number], #lignes input').first()).toHaveValue('2');
    const total1 = await page.locator('#total').innerText();
    await page.fill('#remise', '10 000');
    await page.locator('#remise').dispatchEvent('input');
    const total2 = await page.locator('#total').innerText();
    if (total1 === total2) throw new Error('la remise ne change pas le total (' + total1 + ')');
    await page.selectOption('#mode', 'especes');
    await page.fill('#montant_recu', '800 000');
    await page.locator('#montant_recu').dispatchEvent('input');
    const infoMonnaie = (await page.locator('#info').innerText()).replace(/\s+/g, ' ');
    if (!/rendre|monnaie/i.test(infoMonnaie)) throw new Error('pas de monnaie affichée : ' + infoMonnaie.slice(0, 120));
    await page.click('#valider');
    await confirmer(page).catch(() => {});
    await page.waitForURL(/\/ventes\/\d+/);
    await expect(page.locator('.alert').first()).toContainText('enregistrée');
    const recu = await page.request.get(page.url().split('?')[0] + '/recu');
    if (!recu.ok()) throw new Error('reçu inaccessible');
    return `total ${total1} → ${total2} après remise ; ${page.url().match(/ventes\/\d+/)[0]}`;
  });

  await scenario('Caisse : mise en attente et reprise du ticket', page, async () => {
    await page.goto(BASE + '/caisse');
    await page.fill('#recherche', 'Sucre');
    await page.locator('button.produit-tuile', { hasText: 'Sucre' }).first().click();
    await page.click('#enAttente');
    await confirmer(page).catch(() => {});
    await page.waitForLoadState('networkidle');
    await page.locator('button:has-text("En attente")').first().click();   // la liste des tickets en attente est dans un menu
    const reprendre = page.locator('a[href*="/caisse/attente/"]').first();
    await reprendre.waitFor();
    await reprendre.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#lignes')).toContainText('Sucre');
    await page.click('#vider');
    await confirmer(page).catch(() => {});
    return 'ticket mis de côté puis repris';
  });

  await scenario('Caisse : nouveau client depuis la caisse (fenêtre)', page, async () => {
    await page.goto(BASE + '/caisse');
    await page.getByRole('button', { name: 'Nouveau client' }).click();
    await page.locator('#modalClient').waitFor({ state: 'visible' });
    await page.locator('#modalClient button:has-text("Ajouter le client")').click();
    const erreurVide = await page.locator('#erreurClient').innerText().catch(() => '');
    await page.fill('#nc_nom', 'Traoré');
    await page.fill('#nc_prenom', 'Moussa');
    await page.fill('#nc_tel', '66' + String(Date.now()).slice(-7));
    await page.locator('#modalClient button:has-text("Ajouter le client")').click();
    await page.locator('#modalClient').waitFor({ state: 'hidden' });
    const choisi = await page.locator('#client_id option:checked').innerText();
    if (!/Traoré/.test(choisi)) throw new Error('client non sélectionné : ' + choisi);
    return `client créé et choisi (${choisi.trim()})${erreurVide ? ' ; vide refusé : ' + erreurVide.slice(0, 50) : ''}`;
  });

  await scenario('Formulaire client : champ obligatoire, puis création', page, async () => {
    await page.goto(BASE + '/clients/nouveau');
    await page.locator('form button:has-text("Enregistrer")').last().click();
    const invalide = await page.locator('input:invalid, .is-invalid').count();
    if (!invalide) throw new Error('le formulaire vide est accepté');
    // Numéro déjà utilisé par un client : refusé, et le champ est signalé
    await page.fill('#nom', 'Kaba');
    await page.fill('#telephone', '+224 628 33 44 55');
    await Promise.all([page.waitForLoadState('load'), page.locator('form button:has-text("Enregistrer")').last().click()]);
    await expect(page.locator('#telephone')).toHaveClass(/is-invalid/);
    const tel = '62' + String(Date.now()).slice(-7);
    await page.fill('#telephone', tel);
    await Promise.all([page.waitForURL(/\/clients\/\d+/), page.locator('form button:has-text("Enregistrer")').last().click()]);
    await expect(page.locator('.alert').first()).toContainText('ajouté');
    return 'vide refusé, doublon de téléphone signalé sur le champ, puis création';
  });

  await scenario('Dépense en espèces', page, async () => {
    await page.goto(BASE + '/depenses');
    const form = page.locator('form:has(input[name=motif])').first();
    await form.locator('input[name=motif]').fill('Transport marchandise');
    await form.locator('input[name=montant]').fill('25 000');
    const cat = form.locator('select[name=categorie]');
    if (await cat.count()) await cat.selectOption({ label: 'Transport' }).catch(() => {});
    await form.locator('button').last().click();
    await confirmer(page).catch(() => {});
    await page.waitForLoadState('networkidle');
    await expect(page.locator('body')).toContainText('Transport marchandise');
    return 'dépense visible dans la liste';
  });

  await scenario('Fenêtre de confirmation : « Annuler » ne fait rien', page, async () => {
    await page.goto(BASE + '/depenses');
    const avant = await page.locator('tbody tr').count();
    const bouton = page.locator('form[data-confirmer] button, button[data-confirmer]').first();
    if (!(await bouton.count())) return 'aucune action à confirmer sur la page';
    await bouton.click();
    await page.locator('[data-oui]').waitFor({ state: 'visible' });
    await page.keyboard.press('Escape');
    await page.waitForTimeout(500);
    if ((await page.locator('tbody tr').count()) !== avant) throw new Error('action exécutée malgré le refus');
    return 'Échap ferme la fenêtre sans rien exécuter';
  });

  await scenario('Devis (proforma) depuis la caisse puis acompte', page, async () => {
    await page.goto(BASE + '/caisse?proforma=1');
    await page.fill('#recherche', 'Tôle');
    await page.locator('button.produit-tuile', { hasText: 'Tôle' }).first().click();
    await page.click('#enDevis, #valider');
    await confirmer(page).catch(() => {});
    await page.waitForURL(/\/devis\/\d+/);
    // Client à indiquer, puis acompte
    const nom = page.locator('#formClientDevis input[name=client_nom]');
    if (await nom.count()) {
      await nom.fill('Chantier Bangoura');
      await page.locator('#formClientDevis button').click();
      await page.waitForLoadState('load');
    }
    await page.fill('#montant_acompte', '20 000');
    await page.locator('#formAcompte button').click();
    await confirmer(page);
    await page.waitForLoadState('load');
    await expect(page.locator('body')).toContainText('Acompte déjà versé');
    return 'devis créé, client indiqué, acompte encaissé';
  });

  await scenario('Produit : prix de vente inférieur au prix d\'achat refusé', page, async () => {
    await page.goto(BASE + '/produits/nouveau');
    await page.fill('#designation', 'Test perte');
    await page.fill('#prix_achat', '10 000');
    await page.fill('#prix_vente', '5 000');
    await page.locator('button:has-text("Ajouter le produit")').click();
    await page.waitForLoadState('load');
    await expect(page.locator('body')).toContainText(/inférieur au prix d.achat/);
    return 'vente à perte bloquée';
  });

  await scenario('Notifications et menu du compte', page, async () => {
    await page.goto(BASE + '/tableau-de-bord');
    await page.locator('header button:has(.bi-bell), [aria-label*="otification"]').first().click();
    await page.waitForTimeout(400);
    await page.locator('header .dropdown-toggle, header button:has-text("Mamadou")').first().click();
    await expect(page.locator('body')).toContainText('Se déconnecter');
    return 'menus déroulants ouverts';
  });

  // ---------- Téléphone ----------
  await page.setViewportSize({ width: 360, height: 780 });

  await scenario('Téléphone : menu latéral (ouvrir, voile, Échap)', page, async () => {
    await page.goto(BASE + '/tableau-de-bord');
    const menu = page.locator('aside').first();
    await page.click('[data-ouvrir-menu]');
    await page.waitForTimeout(400);
    if (!(await page.evaluate(() => document.body.classList.contains('menu-ouvert')))) throw new Error('le menu ne s\'ouvre pas');
    await page.click('.voile-menu', { position: { x: 340, y: 400 } });
    await page.waitForTimeout(400);
    if (await page.evaluate(() => document.body.classList.contains('menu-ouvert'))) throw new Error('le voile ne ferme pas le menu');
    await page.click('[data-ouvrir-menu]');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    if (await page.evaluate(() => document.body.classList.contains('menu-ouvert'))) throw new Error('Échap ne ferme pas le menu');
    await page.click('[data-ouvrir-menu]');
    await menu.locator('a[href$="/clients"]').click();
    await page.waitForURL(/\/clients/);
    return 'ouverture, voile, Échap et navigation OK';
  });

  await scenario('Téléphone : vente complète à la caisse (Orange Money)', page, async () => {
    await page.goto(BASE + '/caisse');
    await page.fill('#recherche', 'Huile');
    await page.locator('button.produit-tuile', { hasText: 'Huile' }).first().click();
    await page.selectOption('#mode', 'orange_money');
    if (await page.locator('#reference').isVisible()) await page.fill('#reference', 'OM-778899');
    await page.locator('#valider').scrollIntoViewIfNeeded();
    const deborde = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    await page.click('#valider');
    await confirmer(page).catch(() => {});
    await page.waitForURL(/\/ventes\/\d+/);
    await page.screenshot({ path: path.join(OUT, 'telephone_vente.png') });
    return `vente enregistrée ; débordement ${deborde}px`;
  });

  await scenario('Téléphone : vitrine publique (panier et commande)', page, async () => {
    const r = await page.request.get(BASE + '/parametres');
    const html = await r.text();
    const lien = html.match(/\/vitrine\/[a-z0-9-]+/i);
    if (!lien) return 'vitrine non activée sur la démo : ignorée';
    const pub = await contexte.browser().newContext({ viewport: { width: 360, height: 780 }, ...video(360, 780) });
    const p2 = await pub.newPage();
    await p2.goto(BASE + lien[0]);
    const ajout = p2.locator('button:has-text("Ajouter"), button.ajouter').first();
    if (!(await ajout.count())) { await pub.close(); throw new Error('aucun bouton « Ajouter » sur la vitrine'); }
    await ajout.click();
    const deborde = await p2.evaluate(() => document.documentElement.scrollWidth - innerWidth);
    await p2.screenshot({ path: path.join(OUT, 'telephone_vitrine.png') });
    // Commande envoyée comme un vrai client (le formulaire refuse les envois instantanés des robots)
    await p2.click('#ouvrirPanier');
    await p2.locator('#panier.show').waitFor();
    await p2.fill('#nom', 'Client Vitrine E2E');
    await p2.fill('#formCommande input[name=telephone]', '620 11 22 33');
    await p2.waitForTimeout(2200);
    await Promise.all([p2.waitForURL(/\/merci/), p2.click('#formCommande button:has-text("Envoyer ma commande")')]);
    const reference = (await p2.locator('body').innerText()).match(/DV-\d{4}-\d+/)?.[0];
    await pub.close();
    if (!reference) throw new Error('pas de référence de commande sur la page de remerciement');
    return `produit ajouté, commande ${reference} envoyée ; débordement ${deborde}px`;
  });

  await scenario('Vente à crédit avec échéance, puis report de l\'échéance', page, async () => {
    await page.goto(BASE + '/caisse');
    await page.locator('button.produit-tuile:not([disabled])').first().click();
    const client = await page.evaluate(() => { const s = document.getElementById('client_id'); const o = [...s.options].find(o => o.value && o.dataset.retard === '0' && o.dataset.du === '0') || [...s.options].find(o => o.value && o.dataset.retard === '0'); s.value = o.value; s.dispatchEvent(new Event('change', { bubbles: true })); return o.text.split(' —')[0]; });
    if (await page.locator('#blocEcheance').isVisible()) throw new Error('échéance proposée alors que la vente est payée');
    await page.fill('#montant_recu', '1 000');
    await page.locator('#montant_recu').dispatchEvent('input');
    await expect(page.locator('#blocEcheance')).toBeVisible();
    const dans10 = new Date(Date.now() + 10 * 864e5).toISOString().slice(0, 10);
    await page.fill('#echeance', dans10);
    await page.click('#valider');
    await confirmer(page).catch(() => {});
    await page.waitForURL(/\/ventes\/\d+/);
    await expect(page.locator('#echeance')).toContainText(dans10.split('-').reverse().join('/'));
    await page.locator('summary:has-text("Reporter l\'échéance")').click();
    const dans20 = new Date(Date.now() + 20 * 864e5).toISOString().slice(0, 10);
    await page.fill('#nouvelle_echeance', dans20);
    await Promise.all([page.waitForNavigation(), page.click('button:has-text("Enregistrer la nouvelle date")')]);
    await expect(page.locator('.alert-success').first()).toContainText(dans20.split('-').reverse().join('/'));
    return `crédit pour ${client} : échéance ${dans10} puis reportée au ${dans20}`;
  });

  await scenario('Crédits clients : filtres « En retard » et « Échéance dans 7 jours »', page, async () => {
    await page.goto(BASE + '/credits');
    const total = (await page.locator('.entete-page').innerText()).replace(/\s+/g, ' ');
    await page.click('a:has-text("En retard")');
    await page.waitForURL(/vue=retard/);
    const retards = await page.locator('tbody tr .etat-rupture').count();
    await page.click('a:has-text("Échéance dans 7 jours")');
    await page.waitForURL(/vue=semaine/);
    await page.click('a:has-text("Tous")');
    return `${total.slice(0, 80)} ; ${retards} client(s) en retard`;
  });

  await scenario('Caisse : recherche d\'un client (si la boutique en a beaucoup)', page, async () => {
    await page.goto(BASE + '/caisse');
    if (!(await page.locator('#rechercheClient').count())) {
      const n = await page.locator('#client_id option').count();
      return `${n - 1} clients : tous dans la liste (pas de recherche nécessaire)`;
    }
    await page.locator('#rechercheClient').pressSequentially('62', { delay: 50 });
    await page.locator('#resultatsClients button').first().waitFor();
    await page.locator('#resultatsClients button').first().click();
    return 'client trouvé et choisi : ' + await page.locator('#client_id option:checked').innerText();
  });

  // En dernier : la clôture ferme la caisse de la journée pour ce caissier
  await scenario('Clôture de caisse : comptage billet par billet et rapport Z', page, async () => {
    await page.goto(BASE + '/caisse/cloture');
    await page.locator('summary:has-text("Compter billet par billet")').click();
    await page.fill('#billet20000', '3');
    await page.fill('#billet5000', '2');
    await page.fill('#billet500', '4');
    await expect(page.locator('#totalBillets')).toContainText('72 000');
    if (!(await page.locator('#especes_comptees').evaluate(e => e.readOnly))) throw new Error('le montant compté reste modifiable alors que les billets sont comptés');
    if (await page.locator('#motif').isVisible()) await page.selectOption('#motif', { index: 1 });
    await page.click('#boutonCloturer');
    await Promise.all([page.waitForURL(/\/clotures\/\d+/), confirmer(page)]);
    await expect(page.locator('#detailBillets')).toContainText('3 × 20 000 GNF');
    return 'total 72 000 GNF, rapport Z avec le détail des billets';
  });

  console.log('\n=== RÉSULTAT DES SCÉNARIOS ===');
  for (const b of bilan) console.log((b.ok ? 'OK   ' : 'ÉCHEC') + ' | ' + b.nom + ' | ' + b.detail);
  console.log(`\n${bilan.filter(b => b.ok).length}/${bilan.length} scénarios réussis`);
  await contexte.close();   // termine l'enregistrement des vidéos
  await navigateur.close();
  if (process.env.E2E_VIDEO) console.log('Vidéos : ' + VIDEOS);
})();
