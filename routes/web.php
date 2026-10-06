<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\AideController;
use App\Http\Controllers\ApprovisionnementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CategorieController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClotureCaisseController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepenseController;
use App\Http\Controllers\DetteFournisseurController;
use App\Http\Controllers\DevisController;
use App\Http\Controllers\AssistanceController;
use App\Http\Controllers\FournisseurController;
use App\Http\Controllers\InstallationController;
use App\Http\Controllers\LicenceEnLigneController;
use App\Http\Controllers\MotDePasseOublieController;
use App\Http\Controllers\WebhookDjomyController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NouveauteController;
use App\Http\Controllers\InscriptionController;
use App\Http\Controllers\ParametreController;
use App\Http\Controllers\ProduitController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\EtiquetteController;
use App\Http\Controllers\ImportProduitController;
use App\Http\Controllers\PeremptionController;
use App\Http\Controllers\RapportController;
use App\Http\Controllers\ReseauController;
use App\Http\Controllers\TransfertController;
use App\Http\Controllers\TresorerieController;
use App\Http\Controllers\ReapprovisionnementController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\UtilisateurController;
use App\Http\Controllers\VenteController;
use App\Http\Controllers\VenteEnAttenteController;
use App\Http\Controllers\VitrineController;
use Illuminate\Support\Facades\Route;

// --- Public ---
Route::view('/', 'accueil')->name('accueil');

// Première installation : création du compte Propriétaire (uniquement s'il n'en existe aucun)
Route::get('/installation', [InstallationController::class, 'create'])->name('installation');
Route::post('/installation', [InstallationController::class, 'store'])->middleware('throttle:5,1');

// Désabonnement des nouveautés depuis un e-mail : lien signé, sans connexion
Route::get('/emails/desabonner/{user}', [ProfilController::class, 'desabonner'])->middleware('signed')->name('emails.desabonner');

// Vitrine en ligne d'une boutique : catalogue public et commande (sans connexion)
Route::get('/vitrine/{slug}', [VitrineController::class, 'index'])->middleware('throttle:60,1')->name('vitrine.index');
Route::post('/vitrine/{slug}/commande', [VitrineController::class, 'commander'])->middleware('throttle:5,1')->name('vitrine.commander');
Route::get('/vitrine/{slug}/merci', [VitrineController::class, 'merci'])->name('vitrine.merci');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', [AuthController::class, 'create'])->name('login');
    Route::post('/connexion', [AuthController::class, 'store'])->middleware('throttle:10,1');
    // Mot de passe oublié : lien à usage unique envoyé par e-mail
    Route::get('/mot-de-passe-oublie', [MotDePasseOublieController::class, 'create'])->name('mot-de-passe.oublie');
    Route::post('/mot-de-passe-oublie', [MotDePasseOublieController::class, 'envoyer'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reinitialiser-mot-de-passe/{token}', [MotDePasseOublieController::class, 'formulaire'])->name('password.reset');
    Route::post('/reinitialiser-mot-de-passe', [MotDePasseOublieController::class, 'reinitialiser'])->middleware('throttle:10,1')->name('password.update');
    Route::get('/creer-ma-boutique', [InscriptionController::class, 'create'])->name('inscription');
    Route::post('/creer-ma-boutique', [InscriptionController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function () {
    Route::post('/deconnexion', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/profil', [ProfilController::class, 'edit'])->name('profil.edit');
    Route::put('/profil', [ProfilController::class, 'update'])->name('profil.update');
    Route::put('/profil/mot-de-passe', [ProfilController::class, 'motDePasse'])->name('profil.mot-de-passe');
    Route::post('/profil/rappel-mot-de-passe', [ProfilController::class, 'reporterRappel'])->name('profil.rappel-plus-tard');
    Route::post('/notifications/lues', [ProfilController::class, 'notificationsLues'])->name('notifications.lues');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/{id}', [NotificationController::class, 'ouvrir'])->name('notifications.ouvrir');
});

// Notification de paiement envoyée par Djomy (signée ; sans session ni jeton CSRF)
Route::post('/webhooks/djomy', WebhookDjomyController::class)->middleware('throttle:120,1')->name('webhooks.djomy');

// --- Espace boutique ---
Route::middleware(['auth', 'boutique'])->group(function () {
    Route::get('/abonnement', [AssistanceController::class, 'licence'])->name('abonnement');

    // Achat de licence en ligne (Djomy) — réservé à l'administrateur de la boutique
    Route::post('/licence/commander', [LicenceEnLigneController::class, 'commander'])->middleware(['can:parametres.gerer', 'throttle:10,1'])->name('licence.commander');
    Route::get('/licence/retour/{commande:reference}', [LicenceEnLigneController::class, 'retour'])->name('licence.retour');
    Route::get('/licence/commandes/{commande:reference}/statut', [LicenceEnLigneController::class, 'statut'])->name('licence.statut');

    // Communication avec le propriétaire du logiciel
    Route::get('/nouveautes', [NouveauteController::class, 'index'])->name('nouveautes.index');
    Route::post('/nouveautes/{annonce}/lue', [NouveauteController::class, 'lire'])->name('nouveautes.lire');
    Route::get('/aide', [AideController::class, 'index'])->name('aide.index');
    Route::get('/assistance', [AssistanceController::class, 'index'])->name('assistance.index');
    Route::get('/assistance/nouvelle', [AssistanceController::class, 'create'])->name('assistance.create');
    Route::post('/assistance', [AssistanceController::class, 'store'])->name('assistance.store');
    Route::get('/assistance/{demande}', [AssistanceController::class, 'show'])->whereNumber('demande')->name('assistance.show');
    Route::post('/assistance/{demande}/messages', [AssistanceController::class, 'repondre'])->name('assistance.repondre');
    Route::get('/tableau-de-bord', DashboardController::class)->name('dashboard');

    // Ventes et caisse
    Route::middleware('can:ventes.creer')->group(function () {
        Route::get('/caisse', [VenteController::class, 'create'])->name('ventes.create');
        Route::post('/ventes', [VenteController::class, 'store'])->name('ventes.store');
        Route::get('/caisse/produits', [VenteController::class, 'rechercheProduits'])->name('ventes.produits');
        Route::get('/caisse/clients', [VenteController::class, 'rechercheClients'])->middleware('throttle:60,1')->name('ventes.clients');
        Route::get('/caisse/ecran-client', [VenteController::class, 'ecranClient'])->name('ventes.ecran-client');
        Route::post('/caisse/attente', [VenteEnAttenteController::class, 'store'])->name('ventes.attente.store');
        Route::post('/ventes/{vente}/numeros-serie', [\App\Http\Controllers\GarantieController::class, 'enregistrer'])->name('ventes.series');
        Route::post('/ventes/{vente}/livraison', [\App\Http\Controllers\LivraisonController::class, 'programmer'])->name('livraisons.programmer');
        Route::post('/ventes/{vente}/livraison/depart', [\App\Http\Controllers\LivraisonController::class, 'partir'])->name('livraisons.partir');
        Route::post('/ventes/{vente}/livraison/livree', [\App\Http\Controllers\LivraisonController::class, 'livrer'])->name('livraisons.livrer');
        Route::post('/ventes/{vente}/livraison/retirer', [\App\Http\Controllers\LivraisonController::class, 'retirer'])->name('livraisons.retirer');
        Route::get('/caisse/ping', [VenteController::class, 'ping'])->name('ventes.ping');
        Route::post('/caisse/synchroniser', [VenteController::class, 'synchroniser'])->middleware('throttle:30,1')->name('ventes.synchroniser');
        Route::get('/caisse/attente/{attente}', [VenteEnAttenteController::class, 'reprendre'])->name('ventes.attente.reprendre');
        Route::get('/caisse/carte-cadeau', [\App\Http\Controllers\CarteCadeauController::class, 'verifier'])->middleware('throttle:30,1')->name('cartes-cadeaux.verifier');
        Route::post('/cartes-cadeaux', [\App\Http\Controllers\CarteCadeauController::class, 'store'])->name('cartes-cadeaux.store');
        Route::delete('/caisse/attente/{attente}', [VenteEnAttenteController::class, 'destroy'])->name('ventes.attente.destroy');
    });
    Route::middleware('can:ventes.voir')->group(function () {
        Route::get('/ventes', [VenteController::class, 'index'])->name('ventes.index');
        Route::get('/ventes/export', [VenteController::class, 'export'])->name('ventes.export');
        Route::get('/ventes/{vente}', [VenteController::class, 'show'])->name('ventes.show');
        Route::get('/ventes/{vente}/facture', [VenteController::class, 'facture'])->name('ventes.facture');
        Route::get('/ventes/{vente}/recu', [VenteController::class, 'recu'])->name('ventes.recu');
        Route::get('/garanties', [\App\Http\Controllers\GarantieController::class, 'index'])->name('garanties.index');
        Route::get('/livraisons', [\App\Http\Controllers\LivraisonController::class, 'index'])->name('livraisons.index');
        Route::get('/ventes/{vente}/bon-livraison', [\App\Http\Controllers\LivraisonController::class, 'bon'])->name('livraisons.bon');
        Route::get('/retours/{retour}/bon-avoir', [\App\Http\Controllers\AvoirController::class, 'bonRetour'])->name('retours.bon-avoir');
        Route::get('/cartes-cadeaux', [\App\Http\Controllers\CarteCadeauController::class, 'index'])->name('cartes-cadeaux.index');
        Route::get('/cartes-cadeaux/{carte}', [\App\Http\Controllers\CarteCadeauController::class, 'show'])->whereNumber('carte')->name('cartes-cadeaux.show');
        Route::get('/cartes-cadeaux/{carte}/imprimer', [\App\Http\Controllers\CarteCadeauController::class, 'imprimer'])->whereNumber('carte')->name('cartes-cadeaux.imprimer');
    });
    // Devis / factures proforma
    Route::middleware('can:ventes.creer')->group(function () {
        Route::post('/devis', [DevisController::class, 'store'])->name('devis.store');
        Route::post('/devis/{devi}/vente', [DevisController::class, 'convertir'])->name('devis.convertir');
        Route::post('/devis/{devi}/annuler', [DevisController::class, 'annuler'])->name('devis.annuler');
        Route::post('/devis/{devi}/client', [DevisController::class, 'client'])->name('devis.client');
        Route::post('/devis/{devi}/acomptes', [DevisController::class, 'acompte'])->name('devis.acompte');
        Route::get('/acomptes/{acompte}/recu', [DevisController::class, 'recuAcompte'])->name('acomptes.recu');
        Route::post('/devis/{devi}/renouveler', [DevisController::class, 'renouveler'])->name('devis.renouveler');
    });
    Route::middleware('can:ventes.voir')->group(function () {
        Route::get('/devis', [DevisController::class, 'index'])->name('devis.index');
        Route::get('/devis/{devi}', [DevisController::class, 'show'])->name('devis.show');
        Route::get('/devis/{devi}/proforma', [DevisController::class, 'pdf'])->name('devis.pdf');
    });

    // Point et clôture de caisse (rapport Z)
    Route::middleware('can:ventes.creer')->group(function () {
        Route::get('/caisse/cloture', [ClotureCaisseController::class, 'create'])->name('clotures.create');
        Route::post('/caisse/cloture', [ClotureCaisseController::class, 'store'])->name('clotures.store');
    });
    Route::middleware('can:ventes.voir')->group(function () {
        Route::get('/clotures', [ClotureCaisseController::class, 'index'])->name('clotures.index');
        Route::get('/clotures/{cloture}', [ClotureCaisseController::class, 'show'])->name('clotures.show');
        Route::delete('/clotures/{cloture}', [ClotureCaisseController::class, 'destroy'])->name('clotures.destroy');
    });
    Route::post('/ventes/{vente}/retours', [VenteController::class, 'retour'])->middleware('can:ventes.annuler')->name('ventes.retour');
    Route::post('/ventes/{vente}/annuler', [VenteController::class, 'annuler'])->middleware('can:ventes.annuler')->name('ventes.annuler');
    Route::post('/cartes-cadeaux/{carte}/annuler', [\App\Http\Controllers\CarteCadeauController::class, 'annuler'])->middleware('can:ventes.annuler')->name('cartes-cadeaux.annuler');
    Route::post('/cartes-cadeaux/{carte}/prolonger', [\App\Http\Controllers\CarteCadeauController::class, 'prolonger'])->middleware('can:ventes.annuler')->name('cartes-cadeaux.prolonger');
    Route::post('/ventes/{vente}/paiements', [VenteController::class, 'paiement'])->middleware('can:paiements.creer')->name('ventes.paiement');

    // Crédits clients
    Route::get('/credits', [CreditController::class, 'index'])->middleware('can:ventes.voir')->name('credits.index');
    Route::post('/credits/{client}', [CreditController::class, 'store'])->middleware('can:paiements.creer')->name('credits.store');
    Route::post('/ventes/{vente}/echeance', [CreditController::class, 'echeance'])->middleware('can:paiements.creer')->name('ventes.echeance');

    // Produits et stock
    Route::middleware('can:produits.voir')->group(function () {
        Route::get('/produits', [ProduitController::class, 'index'])->name('produits.index');
        Route::get('/produits/export/{format}', [ProduitController::class, 'export'])->whereIn('format', ['excel', 'pdf'])->name('produits.export');
        Route::get('/produits/{produit}', [ProduitController::class, 'show'])->whereNumber('produit')->name('produits.show');
        Route::get('/stock/mouvements', [StockController::class, 'index'])->name('stock.mouvements');
        Route::get('/stock/peremptions', [PeremptionController::class, 'index'])->middleware('fonction:peremptions')->name('stock.peremptions');
    });
    Route::middleware('can:produits.gerer')->group(function () {
        Route::get('/produits/nouveau', [ProduitController::class, 'create'])->name('produits.create');
        Route::get('/produits/import', [ImportProduitController::class, 'create'])->middleware('fonction:import_catalogue')->name('produits.import');
        Route::get('/produits/import/modele', [ImportProduitController::class, 'modele'])->middleware('fonction:import_catalogue')->name('produits.import.modele');
        Route::post('/produits/import/apercu', [ImportProduitController::class, 'apercu'])->middleware('fonction:import_catalogue')->name('produits.import.apercu');
        Route::post('/produits/import', [ImportProduitController::class, 'store'])->middleware('fonction:import_catalogue')->name('produits.import.store');
        Route::get('/produits/etiquettes', [EtiquetteController::class, 'index'])->middleware('fonction:etiquettes')->name('produits.etiquettes');
        Route::get('/promotions', [PromotionController::class, 'index'])->middleware('fonction:promotions')->name('promotions.index');
        Route::post('/promotions', [PromotionController::class, 'store'])->middleware('fonction:promotions')->name('promotions.store');
        Route::post('/promotions/{promotion}/basculer', [PromotionController::class, 'basculer'])->middleware('fonction:promotions')->name('promotions.basculer');
        Route::delete('/promotions/{promotion}', [PromotionController::class, 'destroy'])->middleware('fonction:promotions')->name('promotions.destroy');
        Route::post('/produits/etiquettes', [EtiquetteController::class, 'imprimer'])->middleware('fonction:etiquettes')->name('produits.etiquettes.imprimer');
        Route::post('/produits', [ProduitController::class, 'store'])->name('produits.store');
        Route::get('/produits/{produit}/modifier', [ProduitController::class, 'edit'])->name('produits.edit');
        Route::put('/produits/{produit}', [ProduitController::class, 'update'])->name('produits.update');
        Route::delete('/produits/{produit}', [ProduitController::class, 'destroy'])->name('produits.destroy');
        Route::resource('categories', CategorieController::class)->only(['index', 'store', 'update', 'destroy'])->parameters(['categories' => 'categorie']);
    });
    Route::middleware('can:stock.ajuster')->group(function () {
        Route::get('/stock/inventaire', [StockController::class, 'inventaire'])->name('stock.inventaire');
        Route::post('/stock/inventaire', [StockController::class, 'ajuster'])->name('stock.ajuster');
        Route::post('/stock/peremptions/{produit}/retirer', [PeremptionController::class, 'retirer'])->middleware('fonction:peremptions')->name('stock.peremptions.retirer');
    });
    Route::middleware('can:approvisionnements.gerer')->group(function () {
        Route::get('/dettes-fournisseurs', [DetteFournisseurController::class, 'index'])->name('fournisseurs.dettes');
        Route::get('/stock/a-commander', [ReapprovisionnementController::class, 'index'])->name('stock.a-commander');
        Route::post('/stock/bon-de-commande', [ReapprovisionnementController::class, 'bonCommande'])->name('stock.bon-commande');
        Route::get('/commandes-fournisseur', [\App\Http\Controllers\CommandeFournisseurController::class, 'index'])->name('commandes-fournisseur.index');
        Route::post('/commandes-fournisseur', [\App\Http\Controllers\CommandeFournisseurController::class, 'store'])->name('commandes-fournisseur.store');
        Route::get('/commandes-fournisseur/{commande}', [\App\Http\Controllers\CommandeFournisseurController::class, 'show'])->name('commandes-fournisseur.show');
        Route::get('/commandes-fournisseur/{commande}/bon', [\App\Http\Controllers\CommandeFournisseurController::class, 'pdf'])->name('commandes-fournisseur.pdf');
        Route::post('/commandes-fournisseur/{commande}/solder', [\App\Http\Controllers\CommandeFournisseurController::class, 'solder'])->name('commandes-fournisseur.solder');
        Route::post('/fournisseurs/{fournisseur}/reglements', [DetteFournisseurController::class, 'regler'])->name('fournisseurs.regler');
        Route::resource('approvisionnements', ApprovisionnementController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('/approvisionnements/{approvisionnement}/retours', [\App\Http\Controllers\RetourFournisseurController::class, 'store'])->name('retours-fournisseur.store');
        Route::get('/retours-fournisseur/{retour}/bon', [\App\Http\Controllers\RetourFournisseurController::class, 'bon'])->name('retours-fournisseur.bon');
    });

    // Clients et fournisseurs
    Route::middleware('can:clients.voir')->group(function () {
        Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
        Route::get('/clients/export', [ClientController::class, 'export'])->name('clients.export');
        Route::get('/clients/{client}', [ClientController::class, 'show'])->whereNumber('client')->name('clients.show');
        Route::get('/clients/{client}/releve', [ClientController::class, 'releve'])->name('clients.releve');
        Route::post('/clients/{client}/relancer', [ClientController::class, 'relancer'])->middleware('fonction:relances')->name('clients.relancer');
        Route::post('/clients/{client}/inviter', [ClientController::class, 'inviter'])->middleware('fonction:relances')->name('clients.inviter');
        Route::post('/clients/{client}/souhaiter', [ClientController::class, 'souhaiter'])->middleware('fonction:relances')->name('clients.souhaiter');
        Route::get('/clients/{client}/bon-avoir', [\App\Http\Controllers\AvoirController::class, 'bonClient'])->name('clients.bon-avoir');
    });
    Route::middleware('can:clients.gerer')->group(function () {
        Route::get('/clients/nouveau', [ClientController::class, 'create'])->name('clients.create');
        // Fiches en double : réservé à qui gère les paramètres (la fusion déplace les dettes d'une fiche à l'autre)
        Route::get('/clients/doublons', [ClientController::class, 'doublons'])->middleware('can:parametres.gerer')->name('clients.doublons');
        Route::post('/clients/fusion', [ClientController::class, 'fusionner'])->middleware('can:parametres.gerer')->name('clients.fusionner');
        Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
        Route::get('/clients/{client}/modifier', [ClientController::class, 'edit'])->name('clients.edit');
        Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
        Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');
    });
    Route::resource('fournisseurs', FournisseurController::class)->except(['show'])->middleware('can:fournisseurs.gerer');

    // Dépenses et rapports
    Route::resource('depenses', DepenseController::class)->except(['show', 'create'])->middleware('can:depenses.gerer');
    // Guide de démarrage du tableau de bord : masqué pour la session
    Route::post('/demarrage/masquer', function () {
        session(['demarrage_masque' => true]);

        return back();
    })->name('demarrage.masquer');

    // Gestion d'équipe : chacun pointe ; le responsable voit tout et corrige les oublis
    Route::middleware('fonction:equipe')->group(function () {
        Route::post('/pointage', [\App\Http\Controllers\PointageController::class, 'pointer'])->middleware('throttle:10,1')->name('equipe.pointer');
        Route::get('/equipe', [\App\Http\Controllers\PointageController::class, 'index'])->name('equipe.index');
        Route::post('/equipe', [\App\Http\Controllers\PointageController::class, 'store'])->middleware('can:utilisateurs.gerer')->name('equipe.store');
        Route::put('/equipe/{pointage}', [\App\Http\Controllers\PointageController::class, 'corriger'])->middleware('can:utilisateurs.gerer')->name('equipe.corriger');
        Route::get('/planning', [\App\Http\Controllers\PlanningController::class, 'index'])->name('equipe.planning');
        Route::post('/planning', [\App\Http\Controllers\PlanningController::class, 'store'])->middleware('can:utilisateurs.gerer')->name('equipe.planning.store');
        Route::post('/planning/copier', [\App\Http\Controllers\PlanningController::class, 'copier'])->middleware('can:utilisateurs.gerer')->name('equipe.planning.copier');
        // Commissions des vendeurs : relevé mensuel, versements (enregistrés en dépenses), export pour la paie
        Route::middleware('can:utilisateurs.gerer')->group(function () {
            Route::get('/commissions', [\App\Http\Controllers\CommissionController::class, 'index'])->name('commissions.index');
            Route::get('/commissions/export', [\App\Http\Controllers\CommissionController::class, 'export'])->name('commissions.export');
            Route::post('/commissions', [\App\Http\Controllers\CommissionController::class, 'verser'])->name('commissions.verser');
            Route::delete('/commissions/{versement}', [\App\Http\Controllers\CommissionController::class, 'annuler'])->name('commissions.annuler');
        });
    });

    // Réseau de boutiques (administrateur) et transferts de stock
    Route::get('/mes-boutiques', [ReseauController::class, 'index'])->name('reseau.index');
    Route::post('/mes-boutiques', [ReseauController::class, 'store'])->name('reseau.store');
    Route::post('/mes-boutiques/{boutique}/ouvrir', [ReseauController::class, 'activer'])->name('reseau.activer');
    Route::middleware('can:approvisionnements.gerer')->group(function () {
        Route::get('/transferts', [TransfertController::class, 'index'])->name('transferts.index');
        Route::get('/transferts/nouveau', [TransfertController::class, 'create'])->name('transferts.create');
        Route::post('/transferts', [TransfertController::class, 'store'])->name('transferts.store');
        Route::get('/transferts/{transfert}', [TransfertController::class, 'show'])->whereNumber('transfert')->name('transferts.show');
        Route::post('/transferts/{transfert}/recevoir', [TransfertController::class, 'recevoir'])->name('transferts.recevoir');
        Route::post('/transferts/{transfert}/annuler', [TransfertController::class, 'annuler'])->name('transferts.annuler');
    });
    Route::middleware('can:tresorerie.gerer')->group(function () {
        Route::get('/tresorerie', [TresorerieController::class, 'index'])->middleware('fonction:tresorerie')->name('tresorerie.index');
        Route::post('/tresorerie', [TresorerieController::class, 'store'])->middleware('fonction:tresorerie')->name('tresorerie.store');
        Route::get('/tresorerie/{compte}', [TresorerieController::class, 'journal'])->middleware('fonction:tresorerie')->name('tresorerie.journal');
    });
    Route::middleware('can:rapports.voir')->group(function () {
        Route::get('/rapports', [RapportController::class, 'index'])->name('rapports.index');
        Route::get('/controle-integrite', [\App\Http\Controllers\IntegriteController::class, 'index'])->name('integrite.index');
        Route::post('/archives-fiscales', [\App\Http\Controllers\IntegriteController::class, 'archiver'])->name('integrite.archiver');
        Route::get('/archives-fiscales/{archive}', [\App\Http\Controllers\IntegriteController::class, 'telecharger'])->whereNumber('archive')->name('integrite.telecharger');
        Route::get('/analyse-des-ventes', [\App\Http\Controllers\AnalyseController::class, 'index'])->name('rapports.analyse');
        Route::get('/rapports/{rapport}/{format}', [RapportController::class, 'export'])
            ->whereIn('format', ['excel', 'pdf', 'ecran'])->name('rapports.export');
    });

    // Administration de la boutique
    Route::middleware('can:utilisateurs.gerer')->group(function () {
        Route::resource('utilisateurs', UtilisateurController::class)->except(['show'])->parameters(['utilisateurs' => 'utilisateur']);
        Route::resource('roles', RoleController::class)->except(['show']);
    });
    Route::middleware('can:parametres.gerer')->group(function () {
        Route::get('/parametres', [ParametreController::class, 'edit'])->name('parametres.edit');
        Route::put('/parametres', [ParametreController::class, 'update'])->name('parametres.update');
    });
});

// --- Administration de la plateforme (propriétaire du logiciel) ---
Route::middleware(['auth', 'super_admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Admin\DashboardController::class)->name('dashboard');
    Route::resource('boutiques', Admin\BoutiqueController::class)->except(['destroy']);
    Route::post('/boutiques/{boutique}/prolonger', [Admin\BoutiqueController::class, 'prolonger'])->name('boutiques.prolonger');
    Route::post('/boutiques/{boutique}/statut', [Admin\BoutiqueController::class, 'statut'])->name('boutiques.statut');
    Route::post('/boutiques/{boutique}/derogations', [Admin\BoutiqueController::class, 'derogations'])->name('boutiques.derogations');
    Route::resource('plans', Admin\PlanController::class)->except(['show']);

    // Paiements en ligne (commandes Djomy)
    Route::get('/paiements-en-ligne', [Admin\CommandeLicenceController::class, 'index'])->name('commandes.index');
    Route::post('/paiements-en-ligne/{commande}/verifier', [Admin\CommandeLicenceController::class, 'verifier'])->name('commandes.verifier');
    Route::post('/paiements-en-ligne/{commande}/activer', [Admin\CommandeLicenceController::class, 'activer'])->name('commandes.activer');
    Route::post('/paiements-en-ligne/diagnostic', [Admin\CommandeLicenceController::class, 'diagnostic'])->middleware('throttle:10,1')->name('commandes.diagnostic');

    // Paiements de licences
    Route::get('/paiements',[Admin\PaiementLicenceController::class, 'index'])->name('paiements.index');
    Route::get('/paiements/export/{format}', [Admin\PaiementLicenceController::class, 'export'])->whereIn('format', ['excel', 'pdf'])->name('paiements.export');
    Route::post('/boutiques/{boutique}/paiements', [Admin\PaiementLicenceController::class, 'store'])->name('paiements.store');
    Route::get('/paiements/{paiement}/recu', [Admin\PaiementLicenceController::class, 'recu'])->name('paiements.recu');
    Route::delete('/paiements/{paiement}', [Admin\PaiementLicenceController::class, 'destroy'])->name('paiements.destroy');

    // Utilisateurs de toutes les boutiques
    Route::get('/utilisateurs', [Admin\UtilisateurController::class, 'index'])->name('utilisateurs.index');
    Route::post('/utilisateurs/{utilisateur}/statut', [Admin\UtilisateurController::class, 'statut'])->name('utilisateurs.statut');
    Route::post('/utilisateurs/{utilisateur}/mot-de-passe', [Admin\UtilisateurController::class, 'reinitialiser'])->name('utilisateurs.reinitialiser');

    // Communication avec les clients
    Route::resource('annonces', Admin\AnnonceController::class)->except(['show']);
    Route::get('/demandes', [Admin\DemandeController::class, 'index'])->name('demandes.index');
    Route::get('/demandes/{demande}', [Admin\DemandeController::class, 'show'])->name('demandes.show');
    Route::post('/demandes/{demande}/messages', [Admin\DemandeController::class, 'repondre'])->name('demandes.repondre');
    Route::post('/demandes/{demande}/cloturer', [Admin\DemandeController::class, 'cloturer'])->name('demandes.cloturer');

    // Sauvegardes de la base
    Route::get('/emails', [Admin\EmailController::class, 'index'])->name('emails.index');
    Route::post('/emails/configuration', [Admin\EmailController::class, 'configurer'])->name('emails.configurer');
    Route::post('/emails/test', [Admin\EmailController::class, 'tester'])->middleware('throttle:10,1')->name('emails.tester');
    Route::get('/sauvegardes', [Admin\SauvegardeController::class, 'index'])->name('sauvegardes.index');
    Route::post('/sauvegardes', [Admin\SauvegardeController::class, 'store'])->middleware('throttle:5,1')->name('sauvegardes.store');
    Route::get('/sauvegardes/{nom}', [Admin\SauvegardeController::class, 'telecharger'])->name('sauvegardes.telecharger');
    Route::delete('/sauvegardes/{nom}', [Admin\SauvegardeController::class, 'destroy'])->name('sauvegardes.destroy');

    // Coordonnées de l'éditeur (affichées aux clients)
    Route::get('/parametres', [Admin\ParametreController::class, 'edit'])->name('parametres.edit');
    Route::put('/parametres', [Admin\ParametreController::class, 'update'])->name('parametres.update');
});
