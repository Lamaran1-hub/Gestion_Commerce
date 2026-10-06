<?php

namespace Database\Seeders;

use App\Models\Boutique;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\Depense;
use App\Models\Fournisseur;
use App\Models\Plan;
use App\Models\Produit;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\ApprovisionnementService;
use App\Services\BoutiqueService;
use App\Services\VenteService;
use App\Support\BoutiqueCourante;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Boutique de démonstration réaliste (quincaillerie-alimentation à Kindia). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Boutique::where('slug', 'boutique-demo-kindia')->exists()) {
            return;
        }

        $boutique = app(BoutiqueService::class)->creer(
            ['nom' => 'Boutique Démo Kindia', 'slug' => 'boutique-demo-kindia', 'telephone' => '+224 622 00 00 00', 'ville' => 'Kindia',
                'adresse' => 'Marché central, Kindia', 'email' => 'demo@exemple.com'],
            ['prenom' => 'Mamadou', 'nom' => 'Barry', 'email' => 'demo@exemple.com', 'password' => 'demo1234', 'telephone' => '+224 622 00 00 00'],
        );
        $boutique->update(['plan_id' => Plan::where('nom', 'Commerce')->value('id'), 'statut' => 'actif',
            'abonnement_expire_le' => now()->addMonths(6), 'pied_facture' => 'Les marchandises vendues ne sont ni reprises ni échangées. Merci de votre confiance.']);

        app(BoutiqueCourante::class)->definir($boutique);
        $admin = User::where('email', 'demo@exemple.com')->first();
        auth()->setUser($admin);

        User::create(['boutique_id' => $boutique->id, 'role_id' => Role::where('nom', 'Vendeur')->value('id'),
            'prenom' => 'Aïssatou', 'nom' => 'Diallo', 'email' => 'vendeur@exemple.com', 'password' => 'demo1234']);

        $fournisseurs = collect([
            ['nom' => 'SOGUICOM Distribution', 'contact' => 'M. Kouyaté', 'telephone' => '+224 628 11 22 33', 'adresse' => 'Madina, Conakry'],
            ['nom' => 'Établissements Sylla & Frères', 'contact' => 'Ibrahima Sylla', 'telephone' => '+224 655 44 55 66', 'adresse' => 'Kaloum, Conakry'],
            ['nom' => 'Ciments de Guinée (dépôt)', 'contact' => 'Service commercial', 'telephone' => '+224 620 77 88 99', 'adresse' => 'Kindia'],
        ])->map(fn ($f) => Fournisseur::create($f));

        $cat = collect(['Alimentation', 'Boissons', 'Hygiène', 'Quincaillerie'])->mapWithKeys(fn ($n) => [$n => Categorie::create(['nom' => $n])]);

        $catalogue = [
            // désignation, catégorie, fournisseur, unité, prix achat, prix vente, stock, seuil, code
            ['Riz parfumé 25 kg', 'Alimentation', 0, 'sac', 265_000, 295_000, 40, 8, '6130000000017'],
            ['Huile végétale 5 L', 'Alimentation', 0, 'bidon', 98_000, 115_000, 30, 6, '6130000000024'],
            ['Sucre en poudre 1 kg', 'Alimentation', 0, 'paquet', 11_000, 13_500, 80, 20, '6130000000031'],
            ['Lait concentré sucré 397 g', 'Alimentation', 1, 'boîte', 9_000, 11_000, 120, 24, '6130000000048'],
            ['Concentré de tomate 400 g', 'Alimentation', 1, 'boîte', 7_500, 9_500, 90, 20, '6130000000055'],
            ['Eau minérale Coyah 1,5 L', 'Boissons', 1, 'pièce', 4_000, 5_000, 200, 48, '6130000000062'],
            ['Jus de gingembre 1 L', 'Boissons', 1, 'pièce', 8_000, 10_000, 36, 12, '6130000000079'],
            ['Savon de ménage (carton 24)', 'Hygiène', 1, 'carton', 85_000, 100_000, 15, 4, '6130000000086'],
            ['Détergent en poudre 1 kg', 'Hygiène', 1, 'paquet', 16_000, 20_000, 50, 10, '6130000000093'],
            ['Ciment 50 kg', 'Quincaillerie', 2, 'sac', 78_000, 90_000, 60, 15, '6130000000109'],
            ['Tôle ondulée 2 m', 'Quincaillerie', 2, 'pièce', 55_000, 65_000, 5, 10, '6130000000116'],
            ['Fer à béton 10 mm', 'Quincaillerie', 2, 'pièce', 42_000, 50_000, 25, 10, '6130000000123'],
            ['Clous 70 mm', 'Quincaillerie', 2, 'kg', 14_000, 18_000, 12.5, 5, '6130000000130'],
            ['Peinture blanche 20 L', 'Quincaillerie', 1, 'bidon', 310_000, 360_000, 0, 2, '6130000000147'],
        ];

        $stock = app(\App\Services\StockService::class);
        $produits = collect($catalogue)->map(function ($p) use ($cat, $fournisseurs, $stock) {
            $produit = Produit::create([
                'designation' => $p[0], 'categorie_id' => $cat[$p[1]]->id, 'fournisseur_id' => $fournisseurs[$p[2]]->id,
                'unite' => $p[3], 'prix_achat' => $p[4], 'prix_vente' => $p[5], 'seuil_alerte' => $p[7], 'code_barre' => $p[8],
            ]);
            if ($p[6] > 0) {
                $stock->mouvement($produit, 'stock_initial', $p[6], null, 'Stock initial (reprise WinDev)');
            }

            return $produit;
        });

        $clients = collect([
            ['prenom' => 'Fatoumata', 'nom' => 'Camara', 'telephone' => '+224 621 10 20 30', 'adresse' => 'Quartier Abattoir, Kindia'],
            ['prenom' => 'Alpha', 'nom' => 'Soumah', 'telephone' => '+224 664 50 60 70', 'adresse' => 'Wondy, Kindia'],
            ['prenom' => null, 'nom' => 'Chantier Keita BTP', 'telephone' => '+224 657 80 90 00', 'adresse' => 'Route de Mamou'],
            ['prenom' => 'Mariama', 'nom' => 'Bah', 'telephone' => '+224 628 33 44 55', 'adresse' => 'Manquepas, Kindia'],
            ['prenom' => 'Ousmane', 'nom' => 'Condé', 'telephone' => '+224 622 66 77 88', 'adresse' => 'Banlieue, Kindia'],
        ])->map(fn ($c) => Client::create($c));

        // Un approvisionnement récent
        app(ApprovisionnementService::class)->creer([
            'fournisseur_id' => $fournisseurs[2]->id, 'date_appro' => now()->subDays(3)->toDateString(), 'note' => 'Bon de livraison n° 4521',
            'lignes' => [
                ['produit_id' => $produits[9]->id, 'quantite' => 40, 'prix_achat_unitaire' => 79_000],
                ['produit_id' => $produits[11]->id, 'quantite' => 30, 'prix_achat_unitaire' => 42_000],
            ],
        ]);

        // Ventes réparties sur les derniers mois
        $ventes = app(VenteService::class);
        $modes = ['especes', 'especes', 'especes', 'orange_money', 'mtn_momo'];
        mt_srand(2026);
        $aujourdhui = now();
        // Dates tirées au hasard puis triées : les numéros suivent l'ordre chronologique
        $dates = collect(range(1, 70))->map(fn () => $aujourdhui->copy()->subDays(mt_rand(0, 150))->setTime(mt_rand(8, 19), mt_rand(0, 59)))
            ->map(fn ($d) => $d->isFuture() ? $aujourdhui->copy()->subMinutes(mt_rand(5, 300)) : $d)->sort()->values();
        foreach ($dates as $date) {
            Carbon::setTestNow($date);
            $lignes = collect($produits->where('stock', '>', 3)->random(mt_rand(1, 3)))
                ->map(fn ($p) => ['produit_id' => $p->id, 'quantite' => mt_rand(1, 3)])->all();
            $client = mt_rand(0, 2) ? null : $clients->random();
            $credit = $client && mt_rand(0, 3) === 0;
            try {
                $v = $ventes->creer(['client_id' => $client?->id, 'lignes' => $lignes, 'mode' => $modes[array_rand($modes)],
                    'montant_recu' => $credit ? 0 : null]);
                if ($credit && mt_rand(0, 1)) {
                    $ventes->encaisser($v, intdiv($v->total_ttc, 2), 'especes');
                }
            } catch (\App\Exceptions\OperationRefusee) {
                // stock épuisé pour ce tirage : on passe
            }
            $produits->each->refresh();
        }
        Carbon::setTestNow();

        // Dépenses du mois
        foreach ([['Loyer du magasin', 'Loyer', 1_500_000, 1], ['Facture EDG', 'Électricité', 280_000, 5], ['Transport marchandise Conakry–Kindia', 'Transport', 350_000, 3],
            ['Crédit téléphonique', 'Communication', 50_000, 2]] as [$motif, $categorie, $montant, $jour]) {
            Depense::create(['motif' => $motif, 'categorie' => $categorie, 'montant' => $montant,
                'date_depense' => now()->startOfMonth()->addDays(min($jour, now()->day - 1)), 'user_id' => $admin->id]);
        }

        app(BoutiqueCourante::class)->definir(null);
        auth()->guard()->forgetUser();
        $this->command?->info('Boutique de démonstration créée : demo@exemple.com / demo1234 ('.Vente::withoutGlobalScope('boutique')->count().' ventes)');
    }
}
