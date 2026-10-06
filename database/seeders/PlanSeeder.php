<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['nom' => 'Démarrage', 'prix_mensuel' => 150_000, 'max_utilisateurs' => 2, 'max_produits' => 300, 'max_boutiques' => 1,
                'fonctions' => ['hors_ligne', 'relances', 'etiquettes'],'description' => 'Petite boutique : 1 caisse, 2 utilisateurs.'],
            ['nom' => 'Commerce', 'prix_mensuel' => 300_000, 'max_utilisateurs' => 6, 'max_produits' => 3000, 'max_boutiques' => 3,
                'fonctions' => ['hors_ligne', 'relances', 'etiquettes', 'peremptions', 'promotions', 'fidelite', 'tresorerie', 'import_catalogue', 'equipe', 'vitrine'],
                'description' => 'Boutique avec plusieurs vendeurs.'],
            // Entreprise : toutes les fonctions (fonctions = null), y compris celles ajoutées plus tard
            ['nom' => 'Entreprise', 'prix_mensuel' => 600_000, 'max_utilisateurs' => null, 'max_produits' => null, 'max_boutiques' => null, 'description' => 'Sans limite, assistance prioritaire.'],
        ] as $plan) {
            Plan::firstOrCreate(['nom' => $plan['nom']], $plan);
        }
    }
}
