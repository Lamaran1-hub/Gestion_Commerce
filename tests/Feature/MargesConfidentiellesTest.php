<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\VenteService;
use Tests\TestCase;

/** Sans le droit « prix d'achat et marges », les rapports ne laissent pas deviner les coûts d'achat. */
class MargesConfidentiellesTest extends TestCase
{
    /** Comptable maison : voit les rapports, pas les prix d'achat. */
    private function comptable($b): User
    {
        $role = $this->dans($b, fn () => Role::create(['nom' => 'Assistant', 'permissions' => ['rapports.voir', 'ventes.voir']]));

        return User::create(['boutique_id' => $b->id, 'prenom' => 'Fanta', 'nom' => 'Keita', 'email' => 'fanta@test.gn', 'password' => 'secret123',
            'role_id' => $role->id, 'actif' => true]);
    }

    public function test_rapports_sans_colonnes_de_cout(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);   // achat 250 000, vente 300 000
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $assistant = $this->comptable($b);

        // L'administrateur voit les marges
        $this->actingAs($admin)->get('/rapports/produits-vendus/ecran')->assertOk()->assertSee('Marge')->assertSee('100 000');
        $this->get('/rapports/stock/ecran')->assertSee('Prix d\'achat');
        $this->get('/rapports/resultat/ecran')->assertOk();

        // L'assistant voit les ventes, sans aucune colonne de coût
        $this->actingAs($assistant)->get('/rapports/produits-vendus/ecran')->assertOk()->assertSee('600 000')->assertDontSee('Marge')->assertDontSee('100 000');
        $this->get('/rapports/stock/ecran')->assertOk()->assertDontSee('Prix d\'achat')->assertDontSee('250 000');
        $this->get('/rapports/vendeurs/ecran')->assertOk()->assertDontSee('Marge');
        $this->get('/rapports/categories/ecran')->assertOk()->assertDontSee('Taux de marge');
        $this->get('/rapports/resultat/ecran')->assertForbidden();
        $this->get('/rapports/pertes/excel')->assertForbidden();
        $this->get('/rapports')->assertOk()->assertDontSee('Compte de résultat simplifié')->assertSee('Ventes par produit');
        $this->get('/analyse-des-ventes')->assertOk()->assertDontSee('Marge brute')->assertDontSee('Les plus rentables');
    }
}
