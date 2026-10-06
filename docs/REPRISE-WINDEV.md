# Reprise de l'application WinDev

## Ce qui a été lu dans les projets d'origine

Les fenêtres et le code WLangage des projets sont compilés et illisibles hors de WinDev. En revanche,
l'**analyse** (fichier `.xdd`) donne la structure complète des fichiers de données, et les noms des
fenêtres, requêtes et états décrivent les fonctionnalités. *Gestion de vente* est la version la plus
complète ; *Gestion commerciale* en est une version antérieure plus simple (4 fichiers).

## Correspondance des fichiers de données

| WinDev (Gestion de vente) | Laravel | Changements |
|---|---|---|
| Fournisseur (nom, prenom, Adresse, telephone) | `fournisseurs` | nom + contact, e-mail ; le téléphone n'est plus une clé unique |
| Produit (designation, prix, IDFournisseur, Qte, CodeBarre) | `produits` | prix d'achat **et** de vente en entiers GNF (le prix était un texte « memo ») ; catégorie, unité, seuil d'alerte, photo ; le stock n'est plus saisi à la main |
| Client (nom, prenom, telephone) | `clients` | code client automatique, adresse, e-mail, notes |
| Commande + Produit_commandes (Quantite, PVU) | `ventes` + `lignes_vente` | numéro de vente, remise, TVA, prix d'achat figé pour la marge ; statut validée ou annulée au lieu de `EstSupprime` |
| Crédit (Montantcredi, montantpayer, Reste_a_payer) | `paiements` | le reste à payer se calcule à partir des ventes et des paiements : il ne peut plus être désynchronisé |
| Approvisionnement (Nouvelle_Qte, Nouveau_PAU, Date_Appro) | `approvisionnements` + `lignes_approvisionnement` + `mouvements_stock` | plusieurs produits par réception, fournisseur ; met à jour le prix d'achat |
| Depense (MotifDepense, MontantDepense, dateDepense) | `depenses` | catégorie, note |
| Utilisateur + Role (Role en texte) | `users` + `roles` | droits détaillés par module, propres à chaque boutique ; mots de passe chiffrés (bcrypt) |
| — | `boutiques`, `plans` | nouveau : plusieurs boutiques clientes et abonnements |

## Correspondance des écrans

| WinDev | Laravel |
|---|---|
| FI_Dashboard, FI_GraphMontantVentesPaMois, REQ_vente_par_mois, REQ_sommeCommande_par_date | Tableau de bord |
| FI_Commandes, FEN_cameracodebarre, REQ_ajouter_une_commande, REQ_Update_stock_produit | Caisse (lecteur de code-barres USB ou Bluetooth) |
| FI_Historique_de_commande, REQ_historique_commandes | Historique des ventes |
| ETAT_FactClientVd | Facture A4 (PDF) |
| ETAT_recu_de_commande | Reçu 80 mm |
| FI_Credit, REQ_credit_client | Crédits clients |
| FI_Produit, REQ_ajouter_produit, REQ_liste_produits | Produits |
| FEN_Approvisionement, REQ_ajout_approvisionement | Approvisionnements |
| FI_Client, FEN_Modifier_les_informations_du_cllient | Clients |
| FI_Fournisseur, FEN_Mofifier_les_information_d_un_fournisseur, REQ_fournisseur_et_leur_ptoduits | Fournisseurs |
| FI_Dépenses, REQ_liste_depense | Dépenses |
| FI_Utilisateurs, FEN_Rôles | Utilisateurs, Rôles et droits |

## Importer les données existantes

1. Dans le Centre de Contrôle HFSQL (ou WDMap), exportez `Fournisseur`, `Client` et `Produit` en CSV
   avec la ligne d'en-tête. Le séparateur `;` ou `,` et l'encodage Windows sont acceptés.
2. Placez les fichiers dans un dossier sur le serveur, par exemple `storage/app/import`.
3. Créez la boutique (depuis `/admin` ou l'inscription), puis lancez d'abord une simulation :

```bash
php artisan gestion:import-windev nom-de-la-boutique storage/app/import --simuler
php artisan gestion:import-windev nom-de-la-boutique storage/app/import
```

Les quantités deviennent un « stock initial » tracé. Les prix d'achat n'existaient pas dans WinDev :
complétez-les ensuite dans la fiche de chaque produit pour obtenir des marges justes. L'historique
des commandes et des crédits n'est pas importé ; saisissez les crédits en cours comme des ventes à crédit.
