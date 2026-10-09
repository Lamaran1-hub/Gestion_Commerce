<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PrixClient;
use App\Models\Promotion;
use App\Models\Role;
use App\Models\User;
use App\Services\FusionClients;
use App\Services\VenteService;
use Tests\TestCase;

/** Prix convenus par client : appliqués en caisse et sur devis, encadrés (droits, pas de vente à perte). */
class PrixClientTest extends TestCase
{
    private function revendeur(): array
    {
        [$b, $admin] = $this->creerBoutique();
        $riz = $this->produit($b, ['conditionnement' => 'lot', 'qte_conditionnement' => 4, 'prix_conditionnement' => 1_150_000], 40);   // 300 000 / achat 250 000
        $client = $this->dans($b, fn () => Client::create(['nom' => 'Bah', 'prenom' => 'Ibrahima', 'telephone' => '622334455']));

        return [$b, $admin, $riz, $client];
    }

    private function vendre($b, $admin, ?Client $c, $p, array $ligne = [])
    {
        return $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $c?->id,
            'lignes' => [['produit_id' => $p->id, 'quantite' => 1] + $ligne], 'mode' => 'especes']), $admin);
    }

    public function test_prix_convenu_applique_au_client_et_seulement_a_lui(): void
    {
        [$b, $admin, $riz, $client] = $this->revendeur();

        $this->actingAs($admin)->post("/clients/{$client->id}/prix", ['produit_id' => $riz->id, 'prix' => '300 000'])->assertSessionHasErrors('prix');
        $this->post("/clients/{$client->id}/prix", ['produit_id' => $riz->id, 'prix' => '240 000'])->assertSessionHasErrors('prix');   // sous le prix d'achat
        $this->post("/clients/{$client->id}/prix", ['produit_id' => $riz->id, 'prix' => '285 000'])->assertSessionHas('succes');
        $this->get("/clients/{$client->id}")->assertSee('Prix convenus')->assertSee('285 000')->assertSee('5,0 %');

        $this->assertSame(285_000, $this->vendre($b, $admin, $client, $riz)->total_ttc);
        $this->assertSame(300_000, $this->vendre($b, $admin, null, $riz)->total_ttc, 'client comptoir : prix normal');
        $this->assertSame(1_150_000, $this->vendre($b, $admin, $client, $riz, ['conditionnement' => 1])->total_ttc, 'le lot garde son prix');

        // Une promotion plus basse l'emporte ; le prix convenu reste quand la promo est moins intéressante
        $this->dans($b, fn () => Promotion::create(['nom' => 'Fête', 'type' => 'prix', 'valeur' => 280_000, 'produit_id' => $riz->id,
            'debut' => now()->subDay(), 'fin' => now()->addDay(), 'actif' => true]));
        $this->assertSame(280_000, $this->vendre($b, $admin, $client, $riz)->total_ttc);

        // La caisse connaît les prix convenus (clients préchargés et clients trouvés par la recherche)
        $this->get('/caisse')->assertSee('"'.$client->id.'":{"'.$riz->id.'":285000}', false);
        $this->getJson('/caisse/clients?q=Bah')->assertJsonPath('0.tarifs.'.$riz->id, 285_000);

        // Retirer le prix convenu
        $pc = $this->dans($b, fn () => PrixClient::sole());
        $this->delete("/clients/{$client->id}/prix/{$pc->id}")->assertSessionHas('succes');
        $this->dans($b, fn () => Promotion::query()->delete());
        $this->assertSame(300_000, $this->vendre($b, $admin, $client, $riz)->total_ttc);
    }

    public function test_droits_et_isolation(): void
    {
        [$b, $admin, $riz, $client] = $this->revendeur();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $this->assertFalse($vendeur->aPermission('ventes.remise'));
        $this->actingAs($vendeur)->post("/clients/{$client->id}/prix", ['produit_id' => $riz->id, 'prix' => '285 000'])->assertForbidden();

        [$autre, $adminAutre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->actingAs($adminAutre->fresh())->post("/clients/{$client->id}/prix", ['produit_id' => $riz->id, 'prix' => '285 000'])->assertNotFound();
        $this->assertSame(0, PrixClient::withoutGlobalScopes()->count());
    }

    public function test_fusion_et_passage_aux_prix_ttc(): void
    {
        [$b, $admin, $riz, $client] = $this->revendeur();
        $huile = $this->produit($b, ['designation' => 'Huile', 'prix_achat' => 80_000, 'prix_vente' => 100_000]);
        $doublon = $this->dans($b, fn () => Client::create(['nom' => 'BAH', 'prenom' => 'Ibrahima']));
        $this->dans($b, function () use ($client, $doublon, $riz, $huile) {
            PrixClient::create(['client_id' => $client->id, 'produit_id' => $riz->id, 'prix' => 285_000]);
            PrixClient::create(['client_id' => $doublon->id, 'produit_id' => $riz->id, 'prix' => 280_000]);
            PrixClient::create(['client_id' => $doublon->id, 'produit_id' => $huile->id, 'prix' => 90_000]);
        });
        $this->dans($b, fn () => app(FusionClients::class)->fusionner($client, $doublon, $admin), $admin);
        $this->assertSame([$riz->id => 285_000, $huile->id => 90_000], PrixClient::parClient([$client->id])[$client->id], 'la fiche conservée garde son prix, reprend les autres');

        // Passage aux prix TVA comprise : le prix convenu suit, le client paie le même montant
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $this->dans($b, fn () => app(\App\Services\ConversionPrixTva::class)->convertir($b->fresh(), true), $admin);
        $this->assertSame(106_200, PrixClient::withoutGlobalScopes()->where('produit_id', $huile->id)->value('prix'));
    }
}
