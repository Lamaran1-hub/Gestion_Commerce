<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Client;
use App\Models\Produit;
use App\Models\User;
use App\Models\Vente;
use App\Support\BoutiqueCourante;
use Database\Seeders\DatabaseSeeder;
use Tests\TestCase;

/** Parcourt tous les écrans avec les données de démonstration : aucun ne doit planter. */
class PagesTest extends TestCase
{
    public function test_tous_les_ecrans_de_la_boutique_s_affichent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'demo@exemple.com')->first();
        app(BoutiqueCourante::class)->definir($admin->boutique);
        $vente = Vente::with('paiements')->first();
        $produit = Produit::first();
        $client = Client::first();
        $appro = Approvisionnement::first();
        app(BoutiqueCourante::class)->definir(null);

        $this->actingAs($admin);
        $pages = [
            '/tableau-de-bord', '/tableau-de-bord?periode=jour', '/tableau-de-bord?periode=annee', '/caisse', '/caisse/produits?q=riz',
            '/ventes', '/ventes?statut=credit&q=V-', "/ventes/{$vente->id}", "/ventes/{$vente->id}/recu",
            '/credits', '/produits', '/produits?etat=alerte', "/produits/{$produit->id}", '/produits/nouveau', "/produits/{$produit->id}/modifier",
            '/categories', '/stock/mouvements', '/stock/inventaire', '/approvisionnements', '/approvisionnements/create', "/approvisionnements/{$appro->id}",
            '/clients', "/clients/{$client->id}", '/clients/nouveau', "/clients/{$client->id}/modifier", '/fournisseurs', '/fournisseurs/create',
            '/depenses', '/rapports', '/utilisateurs', '/utilisateurs/create', '/roles', '/roles/create', '/parametres', '/profil', '/abonnement',
        ];
        foreach ($pages as $page) {
            $this->get($page)->assertOk();
        }

        $this->get("/ventes/{$vente->id}/facture")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/produits/export/excel')->assertOk()->assertDownload();
        $this->get('/produits/export/pdf')->assertOk()->assertDownload();
        $this->get('/ventes/export?format=excel')->assertOk()->assertDownload();
        $this->get('/clients/export')->assertOk()->assertDownload();
        foreach (array_keys(\App\Http\Controllers\RapportController::RAPPORTS) as $rapport) {
            // Boutique de démonstration en formule Démarrage : rapports avancés réservés aux formules supérieures
            if (in_array($rapport, \App\Http\Controllers\RapportController::AVANCES, true)) {
                $this->get("/rapports/{$rapport}/excel")->assertForbidden();
                continue;
            }
            $this->get("/rapports/{$rapport}/excel")->assertOk()->assertDownload();
            $this->get("/rapports/{$rapport}/pdf")->assertOk()->assertDownload();
        }
    }

    public function test_ecrans_du_super_admin_et_pages_publiques(): void
    {
        $this->seed(DatabaseSeeder::class);
        foreach (['/', '/connexion', '/creer-ma-boutique'] as $page) {
            $this->get($page)->assertOk();
        }

        $super = User::where('est_super_admin', true)->first();
        $boutique = User::where('email', 'demo@exemple.com')->first()->boutique;
        $this->actingAs($super);
        foreach (['/admin', '/admin/boutiques', '/admin/boutiques/create', "/admin/boutiques/{$boutique->id}", "/admin/boutiques/{$boutique->id}/edit",
            '/admin/plans', '/admin/plans/create', '/admin/paiements', '/admin/utilisateurs', '/admin/annonces', '/admin/annonces/create',
            '/admin/demandes', '/admin/parametres'] as $page) {
            $this->get($page)->assertOk();
        }
        $this->get('/tableau-de-bord')->assertRedirect(route('admin.dashboard'));

        $this->post('/admin/boutiques', [
            'nom' => 'Pharmacie Espoir', 'statut' => 'actif', 'abonnement_expire_le' => now()->addYear()->toDateString(),
            'plan_id' => $boutique->plan_id, 'admin_prenom' => 'Kadiatou', 'admin_nom' => 'Sow', 'admin_email' => 'kadi@test.gn',
        ])->assertRedirect()->assertSessionHas('succes');
        $this->assertTrue(User::where('email', 'kadi@test.gn')->first()->doit_changer_mot_de_passe);
    }
}
