# Gestion commerciale — gestion commerciale multi-boutiques

Application web (SaaS) de gestion de boutique, réécrite en **Laravel 12** à partir des projets WinDev
*Gestion de vente* et *Gestion commerciale*. Une seule installation sert **plusieurs boutiques clientes** :
chacune a ses propres données, son logo, sa couleur, ses utilisateurs et leurs droits.

## Ce que fait l'application

**Pour chaque boutique**
- Caisse rapide : recherche ou lecteur de code-barres, panier, remise, TVA optionnelle, monnaie à rendre.
- Paiements en espèces, Orange Money, MTN Mobile Money, virement, chèque, carte ; paiement partiel.
- Crédits clients : reste à payer, versements répartis automatiquement sur les ventes les plus anciennes.
- Facture A4 en PDF (logo, montant en lettres) et reçu thermique 80 mm.
- Annulation d'une vente avec motif : le stock est réintégré.
- Produits avec catégories, photo, code-barres, prix d'achat et de vente, seuil d'alerte.
- Stock tracé : chaque entrée ou sortie est journalisée (approvisionnement, vente, annulation, inventaire).
- Approvisionnements multi-produits : le prix d'achat est mis à jour (« Nouveau PAU » de l'ancienne version).
- Inventaire physique avec calcul des écarts.
- Clients, fournisseurs, dépenses par catégorie.
- Tableau de bord : chiffre d'affaires, marge, bénéfice, crédits, valeur du stock, graphique sur 12 mois.
- 7 rapports exportables en Excel et en PDF (ventes, ventes par produit, encaissements, crédits, stock, dépenses, résultat).
- Utilisateurs et rôles personnalisables (Administrateur, Gestionnaire, Vendeur, Magasinier fournis).
- Paramètres : logo, couleur, coordonnées, RCCM, NIF, TVA, mention de bas de facture.

**Pour vous (propriétaire du logiciel)** — espace `/admin`
- Liste des boutiques, création avec mot de passe provisoire, suspension, réactivation.
- Enregistrement des paiements d'abonnement (prolongation de 1 à 36 mois).
- Formules d'abonnement avec limites d'utilisateurs et de produits.
- Inscription libre avec période d'essai (désactivable).

## Installation en local

Prérequis : PHP 8.2 ou plus (extensions `pdo_mysql`, `mbstring`, `gd`, `zip`, `xml`, `intl`), Composer, MySQL/MariaDB.
Aucun Node.js n'est nécessaire : Bootstrap, les icônes, Chart.js et la police sont inclus dans `public/vendor`.

```bash
composer install
cp .env.example .env
php artisan key:generate
# renseigner DB_DATABASE, DB_USERNAME, DB_PASSWORD dans .env
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Avec `APP_ENV=local`, le seeder crée aussi une boutique de démonstration.

| Compte | E-mail | Mot de passe |
|---|---|---|
| Super-administrateur | valeur de `SUPER_ADMIN_EMAIL` (proprietaire@exemple.com) | valeur de `SUPER_ADMIN_PASSWORD` |
| Administrateur de la boutique démo | demo@exemple.com | demo1234 |
| Vendeur de la boutique démo | vendeur@exemple.com | demo1234 |

Changez le mot de passe du super-administrateur dès la première connexion.

## Déploiement sur Hostinger

Voir [`docs/DEPLOIEMENT-HOSTINGER.md`](docs/DEPLOIEMENT-HOSTINGER.md).

## Reprendre les données d'une installation WinDev

Voir [`docs/REPRISE-WINDEV.md`](docs/REPRISE-WINDEV.md) : correspondance des tables et commande d'import.

## Comment l'isolation entre boutiques est garantie

- Toutes les tables métier ont une colonne `boutique_id`.
- Le trait `App\Models\Concerns\AppartientABoutique` ajoute un filtre global sur la boutique de
  l'utilisateur connecté et remplit `boutique_id` à la création. Une création sans boutique est refusée.
- Le middleware `DefinirBoutique` s'exécute avant la résolution des modèles dans les URL :
  ouvrir `/ventes/123` d'une autre boutique renvoie une erreur 404.
- Les règles de validation (`exists`, `unique`) sont limitées à la boutique courante.
- Les tests `tests/Feature/IsolationTest.php` vérifient ces points.

## Règles métier

- Les montants sont des entiers en GNF (jamais de nombres à virgule pour l'argent).
- Le stock n'est modifié que par `App\Services\StockService`, qui journalise chaque mouvement et refuse le stock négatif.
- Une vente, ses lignes, la sortie de stock et le paiement sont enregistrés dans une même transaction.
- Les numéros (V-2026-00001, AP-2026-00001, CLI-00001) sont continus par boutique et protégés par un verrou.
- Désignation et prix sont figés sur la vente : modifier un produit ne change pas les anciennes factures.
- Produits, clients et fournisseurs supprimés restent dans l'historique (suppression douce).
- Une boutique garde toujours au moins un administrateur actif.

## Structure

```
app/Http/Controllers        écrans de la boutique ; Admin/ pour la plateforme
app/Services                VenteService, StockService, ApprovisionnementService, NumeroService, BoutiqueService
app/Models/Concerns         isolation par boutique, traçabilité created_by / updated_by
app/Console/Commands        gestion:import-windev
config/gestion.php          modes de paiement, unités, permissions, rôles par défaut, durée d'essai
resources/views             Blade + Bootstrap 5
tests/Feature               27 tests (isolation, ventes, crédits, stock, droits, abonnement, tous les écrans)
```

## Tests

```bash
php artisan test
```

## Pistes d'évolution

Devis et bons de livraison, scan du code-barres avec la caméra du téléphone, paiement de l'abonnement par
Orange Money ou MTN en ligne, envoi du reçu par SMS ou WhatsApp, plusieurs dépôts par boutique,
mode hors ligne pour les coupures d'Internet.
