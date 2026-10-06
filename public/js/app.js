// Comportements communs : confirmation, mots de passe, choix « Autre », saisie des montants, menu mobile.

const echapper = (t) => String(t ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
const jetonCsrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

/* ---------- Fenêtre de confirmation des actions sensibles ---------- */

let modaleConfirmation = null;
function creerModaleConfirmation() {
  const el = document.createElement('div');
  el.className = 'modal fade modal-confirmation';
  el.tabIndex = -1;
  el.setAttribute('aria-hidden', 'true');
  el.innerHTML = `
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
      <div class="modal-content">
        <div class="modal-body text-center p-4">
          <div class="icone-confirmation"><i class="bi"></i></div>
          <h2 class="h5 fw-bold mb-2" id="confirmationTitre"></h2>
          <p class="text-doux mb-0" data-message></p>
        </div>
        <div class="modal-footer border-0 pt-0 px-4 pb-4 d-flex gap-2">
          <button type="button" class="btn btn-light flex-fill" data-bs-dismiss="modal">Non, annuler</button>
          <button type="button" class="btn flex-fill" data-oui></button>
        </div>
      </div>
    </div>`;
  el.setAttribute('aria-labelledby', 'confirmationTitre');
  document.body.appendChild(el);
  return el;
}

/**
 * Affiche la fenêtre de confirmation et renvoie une promesse : true si l'utilisateur confirme.
 * options : { message, titre, bouton, type: 'danger' | 'alerte' | 'primaire' }
 */
window.confirmer = function (options = {}) {
  modaleConfirmation ??= creerModaleConfirmation();
  const el = modaleConfirmation;
  const type = options.type || 'primaire';
  const icones = { danger: 'exclamation-octagon', alerte: 'exclamation-triangle', primaire: 'question-circle' };
  el.classList.remove('danger', 'alerte', 'primaire');
  el.classList.add(type);
  el.querySelector('.icone-confirmation i').className = 'bi bi-' + icones[type];
  el.querySelector('h2').textContent = options.titre || 'Confirmer cette action ?';
  el.querySelector('[data-message]').textContent = options.message || '';
  const oui = el.querySelector('[data-oui]');
  oui.textContent = options.bouton || 'Oui, confirmer';
  oui.className = 'btn flex-fill ' + (type === 'danger' ? 'btn-danger' : 'btn-primary');

  const modal = bootstrap.Modal.getOrCreateInstance(el);
  return new Promise((resoudre) => {
    let reponse = false;
    const surOui = () => { reponse = true; modal.hide(); };
    oui.addEventListener('click', surOui, { once: true });
    el.addEventListener('shown.bs.modal', () => oui.focus(), { once: true });
    el.addEventListener('hidden.bs.modal', () => { oui.removeEventListener('click', surOui); resoudre(reponse); }, { once: true });
    modal.show();
  });
};

// Déduit le titre, le bouton et la couleur de la fenêtre à partir du formulaire.
function optionsConfirmation(form) {
  const methode = (form.querySelector('input[name="_method"]')?.value || form.method || 'get').toUpperCase();
  const d = form.dataset;
  let message = d.confirmer;
  if (!message && methode === 'DELETE') message = 'Cet élément sera supprimé. Voulez-vous continuer ?';
  if (!message && (methode === 'PUT' || methode === 'PATCH')) message = 'Les modifications vont être enregistrées. Voulez-vous continuer ?';
  if (!message) return null;

  const texte = message.toLowerCase();
  let titre = 'Confirmer cette action ?', bouton = 'Oui, confirmer', type = 'primaire';
  if (methode === 'DELETE' || texte.startsWith('supprimer')) { titre = 'Confirmer la suppression'; bouton = 'Oui, supprimer'; type = 'danger'; }
  else if (texte.startsWith('annuler')) { titre = "Confirmer l'annulation"; bouton = 'Oui, annuler'; type = 'danger'; }
  else if (/désactiver|suspendre/.test(texte)) { titre = 'Confirmer la désactivation'; bouton = 'Oui, désactiver'; type = 'danger'; }
  else if (methode === 'PUT' || methode === 'PATCH') { titre = 'Confirmer la modification'; bouton = 'Oui, enregistrer'; }
  return { message, titre: d.confirmerTitre || titre, bouton: d.confirmerBouton || bouton, type: d.confirmerType || type };
}

// Toute suppression ou modification passe par la fenêtre de confirmation
// (sauf formulaire marqué data-sans-confirmation).
document.addEventListener('submit', (e) => {
  const form = e.target;
  if (!(form instanceof HTMLFormElement) || 'sansConfirmation' in form.dataset) return;
  if (form.dataset.confirme === '1') { form.dataset.confirme = ''; return; }
  const options = optionsConfirmation(form);
  if (!options) return;

  e.preventDefault();
  const bouton = e.submitter;
  window.confirmer(options).then((ok) => {
    if (!ok) return;
    form.dataset.confirme = '1';
    bouton && bouton.form === form ? form.requestSubmit(bouton) : form.requestSubmit();
  });
});

// Liens sensibles : <a href="…" data-confirmer="…">
document.addEventListener('click', (e) => {
  const lien = e.target.closest('a[data-confirmer]');
  if (!lien) return;
  e.preventDefault();
  window.confirmer({ message: lien.dataset.confirmer, titre: lien.dataset.confirmerTitre, bouton: lien.dataset.confirmerBouton, type: lien.dataset.confirmerType })
    .then((ok) => { if (ok) window.location.href = lien.href; });
});

/* ---------- Œil pour voir le mot de passe saisi ---------- */

function ajouterOeil(input) {
  if (input.dataset.oeil) return;
  input.dataset.oeil = '1';
  const conteneur = document.createElement('div');
  conteneur.className = 'champ-mdp';
  input.parentNode.insertBefore(conteneur, input);
  conteneur.appendChild(input);
  const bouton = document.createElement('button');
  bouton.type = 'button';
  bouton.className = 'voir-mdp';
  bouton.setAttribute('aria-label', 'Afficher le mot de passe');
  bouton.innerHTML = '<i class="bi bi-eye"></i>';
  bouton.addEventListener('click', () => {
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    bouton.innerHTML = `<i class="bi bi-eye${visible ? '-slash' : ''}"></i>`;
    bouton.setAttribute('aria-label', visible ? 'Masquer le mot de passe' : 'Afficher le mot de passe');
    input.focus();
  });
  conteneur.appendChild(bouton);
}
document.querySelectorAll('input[type="password"]').forEach(ajouterOeil);
// Par sécurité, le mot de passe est remasqué avant l'envoi du formulaire
document.addEventListener('submit', (e) => {
  e.target.querySelectorAll?.('input[data-oeil]').forEach((i) => { i.type = 'password'; });
}, true);

/* ---------- Choix « Autre » : précision facultative ---------- */
// <select name="x" data-autre> : si « Autre » est choisi, un champ x_autre apparaît (facultatif).

function preparerAutre(select) {
  const valeurAutre = select.dataset.autre || 'Autre';
  const champ = document.createElement('input');
  champ.type = 'text';
  champ.name = select.name + '_autre';
  champ.maxLength = 150;
  champ.className = 'form-control champ-autre' + (select.classList.contains('form-select-sm') ? ' form-control-sm' : '');
  champ.placeholder = select.dataset.autrePlaceholder || 'Précisez le motif (facultatif)';
  champ.setAttribute('aria-label', champ.placeholder);
  champ.value = select.dataset.autreValeur || '';
  select.insertAdjacentElement('afterend', champ);
  const maj = () => {
    const actif = select.value === valeurAutre;
    champ.classList.toggle('d-none', !actif);
    champ.disabled = !actif;
  };
  select.addEventListener('change', () => { maj(); if (!champ.disabled) champ.focus(); });
  maj();
}
document.querySelectorAll('select[data-autre]').forEach(preparerAutre);

/* ---------- Ajout rapide (catégorie, fournisseur…) sans quitter le formulaire ---------- */
// <button type="button" data-ajout-rapide="URL" data-cible="id_du_select" data-titre="…" data-libelle="…">

let modaleAjout = null;
document.addEventListener('click', (e) => {
  const declencheur = e.target.closest('[data-ajout-rapide]');
  if (!declencheur) return;
  e.preventDefault();
  if (!modaleAjout) {
    modaleAjout = document.createElement('div');
    modaleAjout.className = 'modal fade';
    modaleAjout.tabIndex = -1;
    modaleAjout.innerHTML = `
      <div class="modal-dialog modal-dialog-centered"><form class="modal-content" data-sans-confirmation>
        <div class="modal-header"><h2 class="modal-title h5"></h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button></div>
        <div class="modal-body"><label class="form-label" for="ajoutRapideNom"></label>
          <input id="ajoutRapideNom" name="nom" class="form-control" required maxlength="120">
          <div class="invalid-feedback"></div></div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Annuler</button><button class="btn btn-primary">Ajouter</button></div>
      </form></div>`;
    document.body.appendChild(modaleAjout);
    modaleAjout.addEventListener('shown.bs.modal', () => modaleAjout.querySelector('input').focus());
  }
  const form = modaleAjout.querySelector('form');
  const champ = form.querySelector('input');
  modaleAjout.querySelector('.modal-title').textContent = declencheur.dataset.titre || 'Ajouter';
  form.querySelector('label').textContent = declencheur.dataset.libelle || 'Nom';
  champ.value = '';
  champ.classList.remove('is-invalid');
  const modal = bootstrap.Modal.getOrCreateInstance(modaleAjout);
  form.onsubmit = async (ev) => {
    ev.preventDefault();
    const bouton = form.querySelector('.btn-primary');
    bouton.disabled = true;
    try {
      const rep = await fetch(declencheur.dataset.ajoutRapide, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': jetonCsrf() },
        body: JSON.stringify({ nom: champ.value }),
      });
      const donnees = await rep.json();
      if (!rep.ok) {
        champ.classList.add('is-invalid');
        form.querySelector('.invalid-feedback').textContent = donnees.errors?.nom?.[0] || donnees.message || "L'ajout a échoué.";
        return;
      }
      const select = document.getElementById(declencheur.dataset.cible);
      select?.add(new Option(donnees.nom, donnees.id, true, true));
      select?.dispatchEvent(new Event('change'));
      modal.hide();
    } catch {
      champ.classList.add('is-invalid');
      form.querySelector('.invalid-feedback').textContent = 'Connexion impossible. Réessayez.';
    } finally {
      bouton.disabled = false;
    }
  };
  modal.show();
});

/* ---------- « Me rappeler plus tard » : le rappel disparaît tout de suite ---------- */
document.addEventListener('submit', async (e) => {
  const form = e.target;
  if (!form.matches?.('[data-reporter-rappel]')) return;
  e.preventDefault();
  form.closest('#rappelMdp')?.remove();
  try {
    await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-CSRF-TOKEN': jetonCsrf() } });
  } catch { /* au pire, le rappel réapparaîtra à la page suivante */ }
});

/* ---------- Déconnexion après inactivité ---------- */
// Sans clic ni frappe pendant la durée prévue, la page se recharge : le serveur ferme alors
// la session et affiche la connexion (les données ne restent pas affichées sur un poste abandonné).
(() => {
  const minutes = Number(document.body.dataset.inactivite || 0);
  if (!minutes) return;
  let minuteur;
  const relancer = () => { clearTimeout(minuteur); minuteur = setTimeout(() => window.location.reload(), minutes * 60000 + 5000); };
  ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach((ev) => document.addEventListener(ev, relancer, { passive: true }));
  relancer();
})();

/* ---------- Montants, menu mobile ---------- */

// Affiche « 1 250 000 » pendant la saisie d'un montant ; le serveur ignore les espaces.
function formaterMontant(input) {
  const chiffres = input.value.replace(/\D/g, '');
  input.value = chiffres ? Number(chiffres).toLocaleString('fr-FR').replace(/ | /g, ' ') : '';
}
document.addEventListener('input', (e) => {
  if (e.target.matches('[data-montant]')) formaterMontant(e.target);
});
document.querySelectorAll('[data-montant]').forEach(formaterMontant);

// Menu sur téléphone et tablette : voile sombre, bouton ✕ et touche Échap pour le refermer
(() => {
  const barre = document.querySelector('.barre');
  const bouton = document.querySelector('[data-ouvrir-menu]');
  if (!barre || !bouton) return;
  const voile = document.createElement('div');
  voile.className = 'voile-menu';
  voile.setAttribute('aria-hidden', 'true');
  document.body.appendChild(voile);
  const fermer = document.createElement('button');
  fermer.type = 'button';
  fermer.className = 'fermer-menu';
  fermer.setAttribute('aria-label', 'Fermer le menu');
  fermer.innerHTML = '<i class="bi bi-x-lg"></i>';
  barre.querySelector('.barre-entete')?.appendChild(fermer);

  const basculer = (ouvert) => {
    barre.classList.toggle('ouverte', ouvert);
    document.body.classList.toggle('menu-ouvert', ouvert);
    bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
    if (ouvert) fermer.focus(); else bouton.focus();
  };
  bouton.setAttribute('aria-expanded', 'false');
  bouton.addEventListener('click', () => basculer(!barre.classList.contains('ouverte')));
  voile.addEventListener('click', () => basculer(false));
  fermer.addEventListener('click', () => basculer(false));
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && barre.classList.contains('ouverte')) basculer(false); });
  window.matchMedia('(min-width: 992px)').addEventListener('change', (m) => { if (m.matches) basculer(false); });
})();

// Responsivité : tout tableau qui n'est pas déjà dans un cadre défilant y est placé,
// pour qu'aucune page ne déborde sur téléphone (le tableau défile, pas la page).
document.querySelectorAll('.contenu table.table').forEach((t) => {
  if (t.closest('.table-responsive')) return;
  const cadre = document.createElement('div');
  cadre.className = 'table-responsive';
  t.parentNode.insertBefore(cadre, t);
  cadre.appendChild(t);
});

window.echapper = echapper;
window.gnf = (n) => Math.round(n || 0).toLocaleString('fr-FR').replace(/ | /g, ' ') + ' GNF';
window.nombre = (v) => Number(String(v ?? '').replace(/\D/g, '')) || 0;
