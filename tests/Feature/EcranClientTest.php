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

    public function test_la_vente_validee_remercie_le_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);

        $reponse = $this->actingAs($admin)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => '300 000']);
        $this->followRedirects($reponse)->assertOk()->assertSee("type: 'merci'", false);
    }
}
