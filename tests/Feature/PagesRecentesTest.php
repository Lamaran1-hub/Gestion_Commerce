<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\Client;
use App\Models\Transfert;
use App\Services\ApprovisionnementService;
use App\Services\VenteService;
use Tests\TestCase;

/** Toutes les pages ajoutées récemment s'affichent sans erreur, avec des données. */
class PagesRecentesTest extends TestCase
{
    public function test_pages_s_affichent(): void
    {
        [$a, $admin] = $this->creerBoutique();
        $p = $this->produit($a, ['code_barre' => 'X1'], 30);
        $c = $this->dans($a, fn () => Client::create(['nom' => 'Barry', 'telephone' => '622000001']));
        $this->dans($a, fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => 0]), $admin);
        $this->dans($a, fn () => app(ApprovisionnementService::class)->creer(['date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 5, 'prix_achat_unitaire' => 250_000, 'date_peremption' => now()->addDays(5)->toDateString()]]]), $admin);
        $appro = $this->dans($a, fn () => Approvisionnement::first());

        $a->update(['plan_id' => \App\Models\Plan::where('nom', 'Commerce')->value('id')]);
        $this->actingAs($admin)->post('/mes-boutiques', ['nom' => 'Annexe', 'copier_catalogue' => 1]);
        $this->post("/mes-boutiques/{$a->id}/ouvrir");
        $this->post('/transferts', ['boutique_destination_id' => $a->fresh()->reseau()->last()->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 2]]]);
        $t = Transfert::first();

        foreach (['/tableau-de-bord', '/caisse', '/caisse/cloture', "/clients/{$c->id}", '/credits', '/produits', '/produits/import', '/produits/etiquettes',
            '/promotions', '/stock/a-commander', '/stock/peremptions', '/approvisionnements/create', "/approvisionnements/{$appro->id}",
            '/tresorerie', '/tresorerie/orange_money', '/rapports', '/parametres', '/mes-boutiques', '/transferts', '/transferts/nouveau',
            "/transferts/{$t->id}", '/fournisseurs', '/depenses', '/analyse-des-ventes', '/controle-integrite', '/equipe', '/abonnement',
            '/caisse?proforma=1', '/devis', '/profil', '/planning'] as $url) {
            $this->assertSame(200, $this->get($url)->status(), "Page {$url}");
        }
        foreach (array_keys(\App\Http\Controllers\RapportController::RAPPORTS) as $r) {
            $this->get("/rapports/{$r}/ecran")->assertOk();
        }
    }
}
