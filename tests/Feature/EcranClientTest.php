<?php

namespace Tests\Feature;

use App\Models\Promotion;
use Tests\TestCase;

/** Écran tourné vers le client : il suit le ticket de la caisse et remercie à la validation. */
class EcranClientTest extends TestCase
{
    public function test_la_caisse_ouvre_l_ecran_client_qui_suit_le_ticket(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $canal = "gn-ecran-client-{$b->id}-{$admin->id}";

        $this->actingAs($admin)->get('/caisse')->assertOk()->assertSee('id="ouvrirEcranClient"', false)->assertSee($canal, false);
        $this->get('/caisse?proforma=1')->assertOk()->assertDontSee('id="ouvrirEcranClient"', false);
        $this->get('/caisse/ecran-client')->assertOk()->assertSee('Bienvenue')->assertSee($canal, false)->assertSee($b->nom);
    }

    public function test_l_ecran_affiche_les_promotions_et_la_vitrine(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['vitrine_active' => true]);
        $p = $this->produit($b, ['designation' => 'Huile 5 L', 'prix_vente' => 100_000, 'prix_achat' => 70_000]);
        $this->dans($b, fn () => Promotion::create(['nom' => 'Fête', 'type' => 'pourcentage', 'valeur' => 10, 'produit_id' => $p->id,
            'debut' => now()->subDay(), 'fin' => now()->addDays(3), 'actif' => true]));

        $this->actingAs($admin)->get('/caisse/ecran-client')->assertOk()
            ->assertSee('En promotion en ce moment')->assertSee('Huile 5 L')->assertSee('90 000')
            ->assertSee('/vitrine/'.$b->slug);
    }

    public function test_les_photos_des_produits_suivent_le_ticket_jusqu_a_l_ecran(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, ['designation' => 'Jus mangue', 'prix_vente' => 10_000, 'prix_achat' => 6_000, 'image' => 'produits/jus.jpg']);
        $this->produit($b, ['designation' => 'Savon', 'prix_vente' => 5_000, 'prix_achat' => 3_000]);
        $url = json_encode(asset('storage/produits/jus.jpg'));

        // Catalogue de la caisse et recherche : chaque produit porte sa photo (ou rien)
        $this->actingAs($admin)->get('/caisse')->assertOk()->assertSee('"img":'.$url, false)->assertSee('"img":null', false);
        $this->getJson('/caisse/produits?q=mangue')->assertJsonPath('0.image', asset('storage/produits/jus.jpg'));
        $this->get('/caisse')->assertSee('img: l.produit.img || null', false);

        // L'écran client affiche vignettes, photo du dernier article et photos des promotions
        $this->dans($b, fn () => Promotion::create(['nom' => 'Fête', 'type' => 'pourcentage', 'valeur' => 10, 'produit_id' => $p->id,
            'debut' => now()->subDay(), 'fin' => now()->addDays(3), 'actif' => true]));
        $this->get('/caisse/ecran-client')->assertOk()->assertSee('id="vedettePhoto"', false)->assertSee('class="vignette"', false)
            ->assertSee('src="'.asset('storage/produits/jus.jpg').'"', false);
    }

    public function test_la_vente_validee_remercie_le_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);

        $reponse = $this->actingAs($admin)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => '300 000']);
        $this->followRedirects($reponse)->assertOk()->assertSee("type: 'merci'", false);
    }
}
