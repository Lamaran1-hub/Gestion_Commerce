<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\HistoriquePrix;
use App\Models\Role;
use App\Models\User;
use App\Services\ApprovisionnementService;
use Tests\TestCase;

/** Hausse du prix d'achat à la réception : marges signalées, prix suggéré, ajustement en un clic. */
class MargesApresReceptionTest extends TestCase
{
    public function test_hausse_signalee_et_prix_ajuste(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $riz = $this->produit($b, [], 10);   // achat 250 000, vente 300 000 : marge 16,7 %
        $huile = $this->produit($b, ['designation' => 'Huile', 'prix_achat' => 80_000, 'prix_vente' => 100_000], 5);

        $this->dans($b, fn () => app(ApprovisionnementService::class)->creer(['date_appro' => now()->toDateString(), 'lignes' => [
            ['produit_id' => $riz->id, 'quantite' => 5, 'prix_achat_unitaire' => 280_000],     // +12 % : marge 6,7 %
            ['produit_id' => $huile->id, 'quantite' => 5, 'prix_achat_unitaire' => 78_000],    // baisse : rien à signaler
        ]]), $admin);
        $appro = $this->dans($b, fn () => Approvisionnement::sole());

        $this->actingAs($admin)->get("/approvisionnements/{$appro->id}")->assertOk()
            ->assertSee('Prix d\'achat en hausse', false)->assertSee('16,7 % →')->assertSee('6,7 %')->assertSee('+12,0 %')
            ->assertSee('suggéré 336 000 GNF')->assertSee('value="336 000"', false);
        $this->get('/produits?etat=marge')->assertSee('Riz 25 kg')->assertDontSee('Huile');
        $this->get('/produits')->assertSee('1 à marge faible');

        // Sous le prix d'achat : refusé
        $this->patch("/produits/{$riz->id}/prix-vente", ['prix_vente' => '270 000'])->assertSessionHas('erreur');
        $this->patch("/produits/{$riz->id}/prix-vente", ['prix_vente' => '336 000'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, '300 000 GNF → 336 000 GNF') && str_contains($m, '16,7 %'));
        $this->assertSame(336_000, $riz->fresh()->prix_vente);
        $this->assertSame('Ajustement de marge', $this->dans($b, fn () => HistoriquePrix::where('produit_id', $riz->id)->where('champ', 'prix_vente')->latest('id')->value('origine')));

        $this->get("/approvisionnements/{$appro->id}")->assertSee('prix déjà ajusté');
        $this->get('/produits')->assertDontSee('à marge faible');
    }

    public function test_marges_confidentielles(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $riz = $this->produit($b, [], 10);
        $this->dans($b, fn () => app(ApprovisionnementService::class)->creer(['date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $riz->id, 'quantite' => 5, 'prix_achat_unitaire' => 290_000]]]), $admin);
        $appro = $this->dans($b, fn () => Approvisionnement::sole());

        // Un rôle qui voit les réceptions sans voir les prix d'achat ne voit ni l'alerte ni le filtre
        $role = $this->dans($b, fn () => Role::create(['nom' => 'Aide-magasin', 'permissions' => ['approvisionnements.voir', 'approvisionnements.gerer', 'produits.voir']]));
        $magasinier = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Sékou', 'nom' => 'Camara', 'email' => 'sekou@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => $role->id]));
        $this->actingAs($magasinier)->get("/approvisionnements/{$appro->id}")->assertOk()->assertDontSee('Prix d\'achat en hausse', false);
        $this->get('/produits?etat=marge')->assertOk()->assertDontSee('Marge faible');
        $this->patch("/produits/{$riz->id}/prix-vente", ['prix_vente' => '400 000'])->assertForbidden();
    }
}
