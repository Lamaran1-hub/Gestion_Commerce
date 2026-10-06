# Déploiement sur Hostinger (hébergement mutualisé ou Cloud)

## 1. Préparer l'hébergement (hPanel)

- **PHP** : Avancé > Configuration PHP → version 8.2 ou 8.3. Vérifiez que `gd`, `zip`, `intl`, `mbstring`
  et `pdo_mysql` sont cochés.
- **Base de données** : Bases de données > MySQL → créez la base et l'utilisateur, notez les identifiants.
- **SSH** : Avancé > Accès SSH → activez-le.

## 2. Envoyer les fichiers

Placez le projet **hors** de `public_html`, par exemple dans `~/gestion-commerciale`
(via Git, SSH ou le gestionnaire de fichiers après avoir décompressé l'archive).

```bash
cd ~/gestion-commerciale
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Dans `.env` : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://votre-domaine.com`,
les identifiants MySQL, et un `SUPER_ADMIN_PASSWORD` solide.

## 3. Pointer le domaine sur le dossier public

Solution recommandée : remplacer `public_html` par un lien vers `public`.

```bash
cd ~/domains/votre-domaine.com
mv public_html public_html_ancien
ln -s ~/gestion-commerciale/public public_html
```

Si le lien n'est pas possible, copiez `deploiement/htaccess-racine` sous le nom `.htaccess` à la racine
du projet placé dans `public_html` : il redirige toutes les requêtes vers le dossier `public`.

## 4. Base de données et fichiers

```bash
php artisan migrate --force
php artisan db:seed --force          # formules + compte super-administrateur (pas de démo en production)
php artisan storage:link             # logos et photos des produits
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 5. Après la mise en ligne

- Connectez-vous avec le super-administrateur et changez son mot de passe (menu Mon profil).
- Activez le certificat SSL (hPanel > Sécurité > SSL) : obligatoire pour les mots de passe.
- Sauvegardes : activez les sauvegardes quotidiennes de la base dans hPanel.
- Mise à jour du code : `git pull`, `composer install --no-dev`, `php artisan migrate --force`, puis les trois commandes `cache`.
- Pour fermer l'inscription libre : `GESTION_INSCRIPTION_OUVERTE=false` dans `.env`, puis `php artisan config:cache`.

## 6. Sécurité : clés API et mots de passe

Les secrets (base de données, Djomy, serveur d'e-mails, `APP_KEY`) vivent **uniquement** dans le fichier `.env`, hors de `public_html`.
Ils ne sont jamais dans le code, jamais envoyés par WhatsApp ou e-mail, jamais copiés dans une capture d'écran.

Avant d'ouvrir le site, puis après chaque mise à jour :

```bash
php artisan securite:verifier     # doit finir sans « point critique »
```

La commande contrôle : `APP_DEBUG=false`, HTTPS, cookie de session sécurisé, sessions chiffrées, comptes avec un mot de passe connu
(démonstration, installation), identifiants Djomy, fichiers sensibles dans `public/`. Les mêmes alertes s'affichent sur le tableau de bord du propriétaire.

- `.env` en production : `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://…`, `LOG_LEVEL=warning`, `SESSION_ENCRYPT=true`.
- Droits du fichier : `chmod 640 .env` (lisible seulement par votre compte).
- Supprimez la ligne `SUPER_ADMIN_PASSWORD` une fois le compte propriétaire créé.
- Ne mettez jamais la base de démonstration (`demo@gngestion.com`) en ligne.
- **Clé Djomy compromise** (fichier partagé par erreur, ancien prestataire…) : régénérez `DJOMY_CLIENT_SECRET` dans l'espace développeur Djomy,
  remplacez-le dans `.env`, puis `php artisan config:cache`. Même chose pour le mot de passe SMTP (Administration → E-mails).
- **Ne changez jamais `APP_KEY`** sur un site en service : les sessions et le mot de passe SMTP enregistré deviendraient illisibles.
  Gardez-en une copie dans un endroit sûr (gestionnaire de mots de passe).

Protections intégrées : en-têtes de sécurité (CSP, anti-clickjacking, HSTS en HTTPS), blocage d'un compte 15 minutes après
5 mots de passe faux, notifications de paiement Djomy vérifiées par signature HMAC, fichiers envoyés jamais exécutés,
données de chaque boutique isolées, registre anti-fraude des ventes.
