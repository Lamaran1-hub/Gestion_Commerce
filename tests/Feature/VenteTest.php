<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MouvementStock;
use App\Models\Vente;
use App\Services\VenteService;
use Tests\TestCase;

class VenteTest extends TestCase
{
    public function test_une_vente_diminue_le_stock_et_trace_le_mouvement(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);

        $this->actingAs($admin)->post('/ventes', [
            'lignes' => [['produit_id' => $p->id, 'quantite' => 3]], 'mode' => 'orange_money', 'montant_recu' => '900 000', 'reference' => 'OM123',
        ])->assertRedirect();

        $this->assertEquals(7, $p->fresh()->stock);
        $vente = Vente::withoutGlobalScopes()->first();
        $this->assertSame(900_000, $vente->total_ttc);
        $this->assertSame(900_000, $vente->montant_paye);
        $this->assertSame('orange_money', $vente->paiements()->first()->mode);
        $this->assertSame(250_000, $vente->lignes->first()->prix_achat);
        $this->assertTrue(MouvementStock::withoutGlobalScopes()->where(['produit_id' => $p->id, 'type' => 'vente', 'quantite' => -3])->exists());
    }

    public function test_stock_insuffisant_refuse_la_vente_entierement(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p1 = $this->produit($b, ['designation' => 'Huile'], 10);
        $p2 = $this->produit($b, ['designation' => 'Sucre'], 1);

        $this->actingAs($admin)->from('/caisse')->post('/ventes', [
            'lignes' => [['produit_id' => $p1->id, 'quantite' => 2], ['produit_id' => $p2->id, 'quantite' => 5]], 'mode' => 'especes',
        ])->assertRedirect('/caisse')->assertSessionHas('erreur');

        $this->assertSame(0, Vente::withoutGlobalScopes()->count());
        $this->assertEquals(10, $p1->fresh()->stock, 'la transaction doit tout annuler');
    }

    public function test_le_prix_est_celui_du_produit_sans_droit_de_remise(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);
        $vendeur = $this->creerUtilisateur($b, 'Vendeur');

        $this->actingAs($vendeur)->post('/ventes', [
            'lignes' => [['produit_id' => $p->id, 'quantite' => 1, 'prix_unitaire' => 1000]], 'remise' => 50_000, 'mode' => 'especes',
        ])->assertRedirect();

        $v = Vente::withoutGlobalScopes()->first();
        $this->assertSame(300_000, $v->total_ttc);
        $this->assertSame(0, $v->remise);
    }

    public function test_tva_et_remise_sont_calculees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $p = $this->produit($b);

        $this->actingAs($admin)->post('/ventes', [
            'lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'remise' => '100 000', 'mode' => 'especes',
        ]);

        $v = Vente::withoutGlobalScopes()->first();
        $this->assertSame(600_000, $v->total_ht);
        $this->assertSame(90_000, $v->total_tva); // 18 % de 500 000
        $this->assertSame(590_000, $v->total_ttc);
    }

    public function test_vente_a_credit_exige_un_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b);

        $this->actingAs($admin)->post('/ventes', [
            'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'montant_recu' => '100 000',
        ])->assertSessionHas('erreur');
        $this->assertSame(0, Vente::withoutGlobalScopes()->count());
    }

    public function test_credit_puis_versement_reparti_sur_les_ventes_les_plus_anciennes(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Camara']));
        $service = app(VenteService::class);

        $v1 = $this->dans($b, fn () => $service->creer(['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 100_000]), $admin);
        $this->travel(1)->days();
        $v2 = $this->dans($b, fn () => $service->creer(['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]), $admin);
        $this->assertSame(500_000, $this->dans($b, fn () => $client->soldeDu()));

        $this->actingAs($admin)->post("/credits/{$client->id}", ['montant' => '250 000', 'mode' => 'mtn_momo'])->assertSessionHas('succes');

        $this->assertSame(300_000, $v1->fresh()->montant_paye, 'la plus ancienne est soldée en premier');
        $this->assertSame(50_000, $v2->fresh()->montant_paye);
        $this->assertSame(250_000, $this->dans($b, fn () => $client->soldeDu()));

        $this->actingAs($admin)->post("/credits/{$client->id}", ['montant' => '999 999 999', 'mode' => 'especes'])->assertSessionHas('erreur');
    }

    public function test_annulation_reintegre_le_stock(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 4]]]), $admin);
        $this->assertEquals(6, $p->fresh()->stock);

        $this->actingAs($admin)->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])->assertSessionHas('succes');
        $this->assertEquals(10, $p->fresh()->stock);
        $this->assertSame('annulee', $v->fresh()->statut);

        $this->actingAs($admin)->post("/ventes/{$v->id}/annuler", ['motif' => 'Deux fois'])->assertSessionHas('erreur');
        $this->assertEquals(10, $p->fresh()->stock, 'pas de double réintégration');
    }

    public function test_approvisionnement_augmente_le_stock_et_met_a_jour_le_prix_d_achat(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 2);

        $this->actingAs($admin)->post('/approvisionnements', [
            'date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 10, 'prix_achat_unitaire' => '260 000', 'prix_vente' => '320 000']],
        ])->assertRedirect();

        $p->refresh();
        $this->assertEquals(12, $p->stock);
        $this->assertSame(260_000, $p->prix_achat);
        $this->assertSame(320_000, $p->prix_vente);
    }

    public function test_inventaire_corrige_le_stock(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);

        $this->actingAs($admin)->post('/stock/inventaire', ['comptes' => [$p->id => 7.5]])->assertSessionHas('succes');
        $this->assertEquals(7.5, $p->fresh()->stock);
        $this->assertTrue(MouvementStock::withoutGlobalScopes()->where(['type' => 'ajustement', 'quantite' => -2.5])->exists());
    }

    private function creerUtilisateur($b, string $role)
    {
        return \App\Models\User::create(['boutique_id' => $b->id, 'role_id' => \App\Models\Role::withoutGlobalScopes()->where(['boutique_id' => $b->id, 'nom' => $role])->value('id'),
            'prenom' => 'Test', 'nom' => $role, 'email' => strtolower($role).'@test.gn', 'password' => 'secret123']);
    }
}
