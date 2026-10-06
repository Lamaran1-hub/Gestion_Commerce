<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Produit;
use App\Services\VenteService;
use Tests\TestCase;

class PrixDeGrosTest extends TestCase
{
    private function produitGros($b): Produit
    {
        // Détail 300 000, gros 280 000 dès 10 unités, achat 250 000
        return $this->produit($b, ['prix_gros' => 280_000, 'quantite_gros' => 10], 50);
    }

    public function test_prix_de_gros_a_partir_de_la_quantite(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produitGros($b);

        $petit = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 9]], 'mode' => 'especes']), $admin);
        $this->assertSame(300_000, $petit->lignes->first()->prix_unitaire);

        $gros = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 10]], 'mode' => 'especes']), $admin);
        $this->assertSame(280_000, $gros->lignes->first()->prix_unitaire);
        $this->assertSame(2_800_000, $gros->total_ttc);
    }

    public function test_client_grossiste_toujours_au_prix_de_gros(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produitGros($b);
        $revendeur = $this->dans($b, fn () => Client::create(['nom' => 'Boutique Kaba', 'grossiste' => true]));

        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $revendeur->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $this->assertSame(280_000, $v->lignes->first()->prix_unitaire);

        // Un prix envoyé par le navigateur est ignoré sans droit de remise
        $v2 = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1, 'prix_unitaire' => 1000]], 'mode' => 'especes'], false), $admin);
        $this->assertSame(300_000, $v2->lignes->first()->prix_unitaire);
    }

    public function test_regles_du_prix_de_gros_sur_la_fiche_produit(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $base = ['designation' => 'Riz', 'unite' => 'sac', 'prix_achat' => '250 000', 'prix_vente' => '300 000'];

        // Plus cher que le détail : refusé ; sous le coût d'achat : refusé
        $this->actingAs($admin)->post('/produits', $base + ['prix_gros' => '310 000', 'quantite_gros' => 10])->assertSessionHasErrors('prix_gros');
        $this->post('/produits', $base + ['prix_gros' => '240 000', 'quantite_gros' => 10])->assertSessionHasErrors('prix_gros');

        $this->post('/produits', $base + ['prix_gros' => '280 000', 'quantite_gros' => 10])->assertSessionHasNoErrors();
        $p = $this->dans($b, fn () => Produit::where('designation', 'Riz')->first());
        $this->assertSame(280_000, $p->prix_gros);

        // Case « grossiste » sur la fiche client
        $this->post('/clients', ['nom' => 'Revendeur', 'grossiste' => 1])->assertRedirect();
        $this->assertTrue($this->dans($b, fn () => Client::where('nom', 'Revendeur')->first()->grossiste));
        $this->get('/caisse')->assertOk()->assertSee('(grossiste)');
    }

    public function test_reception_qui_rendrait_le_prix_de_gros_deficitaire_refusee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produitGros($b);
        $this->actingAs($admin)->post('/approvisionnements', ['date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 5, 'prix_achat_unitaire' => '290 000']]])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'prix de gros'));
        $this->assertEquals(50, $p->fresh()->stock);
    }
}
