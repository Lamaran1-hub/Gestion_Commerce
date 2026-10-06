<?php

return [
    // Logo du logiciel (connexion, onglet du navigateur, espace super-administrateur)
    'logo' => env('GESTION_LOGO', 'img/logo.svg'),
    // Durée d'affichage du logo avant le formulaire de connexion (secondes)
    'duree_accueil' => (int) env('GESTION_DUREE_ACCUEIL', 5),
    // Déconnexion automatique après cette durée sans activité (minutes)
    'inactivite_minutes' => (int) env('GESTION_INACTIVITE_MINUTES', 30),

    // Nombre de sauvegardes quotidiennes conservées (les plus anciennes sont supprimées)
    'sauvegardes_a_garder' => (int) env('GESTION_SAUVEGARDES_A_GARDER', 14),

    'inscription_ouverte' => env('GESTION_INSCRIPTION_OUVERTE', true),
    'jours_essai' => (int) env('GESTION_JOURS_ESSAI', 14),
    'stock_negatif_autorise' => false,

    'super_admin' => [
        'email' => env('SUPER_ADMIN_EMAIL', 'proprietaire@exemple.com'),
        'password' => env('SUPER_ADMIN_PASSWORD', 'ChangezMoi2026!'),
    ],

    // Tous les moyens de paiement utilisés en Guinée (dont ceux de Djomy : PayCard, Kulu, Soutra Money)
    'modes_paiement' => [
        'especes' => 'Espèces',
        'orange_money' => 'Orange Money',
        'mtn_momo' => 'MTN Mobile Money',
        'paycard' => 'PayCard',
        'kulu' => 'Kulu',
        'soutra_money' => 'Soutra Money',
        'carte' => 'Carte bancaire',
        'virement' => 'Virement',
        'cheque' => 'Chèque',
        'autre' => 'Autre',
    ],

    // Fonctions réservées à certaines formules (le propriétaire choisit, formule par formule, et peut en accorder à un client).
    // Le cœur du logiciel (caisse, stock, clients, crédits, devis et factures proforma, dépenses, rapports de base) est toujours inclus.
    'fonctions' => [
        'hors_ligne' => ['Caisse sans connexion', 'wifi-off'],
        'relances' => ['Relances WhatsApp des clients débiteurs', 'whatsapp'],
        'etiquettes' => ['Étiquettes prix et codes-barres', 'upc-scan'],
        'peremptions' => ['Suivi des dates de péremption', 'calendar-x'],
        'promotions' => ['Promotions à durée limitée', 'percent'],
        'fidelite' => ['Programme de fidélité', 'star'],
        'tresorerie' => ['Trésorerie (caisse, mobile money, banque)', 'bank'],
        'import_catalogue' => ['Import du catalogue depuis Excel', 'upload'],
        'rapports_avances' => ['Rapports avancés (vendeurs, catégories, pertes, TVA, heures de pointe, rentabilité)', 'graph-up'],
        'equipe' => ["Gestion d'équipe (pointage, heures travaillées, CA par heure)", 'person-check'],
        'vitrine' => ['Vitrine en ligne (catalogue public et commandes WhatsApp)', 'shop-window'],
    ],

    // Plan comptable SYSCOHADA (espace OHADA, dont la Guinée) pour l'export des écritures ; ajustable par le comptable
    'comptabilite' => [
        'clients' => '411', 'fournisseurs' => '401', 'ventes' => '701', 'achats' => '601', 'tva_collectee' => '4431',
        'remises_accordees' => '673', // points de fidélité utilisés
        'avoirs_clients' => '4191',  // avoirs dus aux clients (retours rendus en bon d'achat)
        'acomptes_clients' => '4191', // avances et acomptes reçus des clients sur commandes
        'cartes_cadeaux' => '4191',   // cartes cadeaux vendues, pas encore dépensées
        'tresorerie' => [
            'especes' => '571', 'autre' => '571',
            'orange_money' => '552', 'mtn_momo' => '552', 'paycard' => '552', 'kulu' => '552', 'soutra_money' => '552',
            'carte' => '521', 'virement' => '521', 'cheque' => '521',
        ],
        'charges' => [
            'Loyer' => '622', 'Électricité' => '605', 'Eau' => '605', 'Transport' => '618', 'Salaires' => '661',
            'Communication' => '628', 'Fournitures' => '604', 'Entretien' => '624', 'Taxes' => '641', 'Autre' => '638',
        ],
        'charges_defaut' => '638',
    ],

    // Limites chiffrées d'une formule (vide = illimité), modifiables par formule et par client
    'limites' => [
        'utilisateurs' => ['max_utilisateurs', 'Utilisateurs actifs'],
        'produits' => ['max_produits', 'Produits'],
        'boutiques' => ['max_boutiques', 'Boutiques (points de vente)'],
    ],

    // Comptes de trésorerie : où se trouve l'argent, et quels moyens de paiement y arrivent
    'comptes_tresorerie' => [
        'caisse' => ['Caisse (espèces)', 'cash-stack', ['especes']],
        'orange_money' => ['Orange Money', 'phone', ['orange_money']],
        'mtn_momo' => ['MTN Mobile Money', 'phone', ['mtn_momo']],
        'autres_mobile' => ['PayCard, Kulu, Soutra Money', 'wallet2', ['paycard', 'kulu', 'soutra_money']],
        'banque' => ['Banque', 'bank', ['virement', 'cheque', 'carte']],
        'autre' => ['Autres moyens', 'three-dots', ['autre']],
    ],

    // Listes de choix proposant « Autre » avec une précision facultative
    'motifs_annulation' => ['Erreur de saisie', 'Le client a renoncé', 'Retour de marchandise', 'Produit défectueux', 'Vente enregistrée en double', 'Autre'],
    'motifs_retour' => ['Produit défectueux', 'Erreur de produit', "Le client a changé d'avis", 'Produit périmé', 'Quantité en trop', 'Autre'],
    'motifs_retour_fournisseur' => ['Produit défectueux', 'Produit périmé ou date trop courte', 'Erreur de livraison (mauvais produit)', 'Livré en trop', 'Colis abîmé', 'Autre'],
    // Billets et pièces en francs guinéens, pour compter le tiroir à la clôture (du plus gros au plus petit)
    'coupures' => [20000, 10000, 5000, 2000, 1000, 500, 100],
    'motifs_ecart_caisse' => ['Erreur de rendu de monnaie', 'Vente non enregistrée', 'Paiement mobile saisi en espèces', 'Billet douteux', 'Autre'],
    'motifs_inventaire' => ['Inventaire périodique', 'Casse ou produit abîmé', 'Perte ou vol', 'Produit périmé', 'Erreur de saisie', 'Autre'],

    'unites' => ['pièce', 'carton', 'paquet', 'sac', 'kg', 'litre', 'mètre', 'boîte', 'bidon'],

    'categories_depense' => ['Loyer', 'Électricité', 'Eau', 'Transport', 'Salaires', 'Communication', 'Fournitures', 'Entretien', 'Taxes', 'Autre'],

    // Permissions attribuables aux rôles, regroupées par module pour l'écran des rôles
    'permissions' => [
        'Tableau de bord' => [
            'dashboard.voir' => 'Voir le tableau de bord et les statistiques',
        ],
        'Ventes' => [
            'ventes.voir' => 'Consulter les ventes',
            'ventes.creer' => 'Enregistrer une vente',
            'ventes.annuler' => 'Annuler une vente',
            'ventes.remise' => 'Accorder une remise',
            'paiements.creer' => 'Encaisser un paiement ou un crédit',
        ],
        'Produits et stock' => [
            'produits.voir' => 'Consulter les produits et le stock',
            'produits.gerer' => 'Créer, modifier et supprimer des produits',
            'produits.prix_achat' => "Voir les prix d'achat et les marges",
            'approvisionnements.gerer' => 'Enregistrer des approvisionnements',
            'stock.ajuster' => 'Corriger le stock (inventaire)',
        ],
        'Clients et fournisseurs' => [
            'clients.voir' => 'Consulter les clients',
            'clients.gerer' => 'Créer, modifier et supprimer des clients',
            'fournisseurs.gerer' => 'Gérer les fournisseurs',
        ],
        'Dépenses et rapports' => [
            'depenses.gerer' => 'Enregistrer et consulter les dépenses',
            'rapports.voir' => 'Consulter et exporter les rapports',
            'tresorerie.gerer' => 'Voir la trésorerie (caisse, mobile money, banque) et enregistrer transferts, apports et retraits',
        ],
        'Administration' => [
            'utilisateurs.gerer' => 'Gérer les utilisateurs et les rôles',
            'parametres.gerer' => 'Modifier les paramètres de la boutique',
        ],
    ],

    // Rôles créés automatiquement pour chaque nouvelle boutique
    'roles_par_defaut' => [
        'Gestionnaire' => [
            'dashboard.voir', 'ventes.voir', 'ventes.creer', 'ventes.annuler', 'ventes.remise', 'paiements.creer',
            'produits.voir', 'produits.gerer', 'produits.prix_achat', 'approvisionnements.gerer', 'stock.ajuster',
            'clients.voir', 'clients.gerer', 'fournisseurs.gerer', 'depenses.gerer', 'rapports.voir', 'tresorerie.gerer',
        ],
        'Vendeur' => [
            'ventes.voir', 'ventes.creer', 'paiements.creer', 'produits.voir', 'clients.voir', 'clients.gerer',
        ],
        'Magasinier' => [
            'produits.voir', 'produits.gerer', 'approvisionnements.gerer', 'stock.ajuster', 'fournisseurs.gerer',
        ],
    ],
];
