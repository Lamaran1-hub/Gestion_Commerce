<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Services\VenteService;
use App\Support\ClientCaisse;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Caisse d'une boutique avec beaucoup de clients : liste allégée + recherche. */
class RechercheClientCaisseTest extends TestCase
{
    public function test_petite_boutique_tous_les_clients_sans_recherche(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->dans($b, fn () => Client::create(['nom' => 'Barry', 'prenom' => 'Ousmane', 'telephone' => '620000001']));

        $this->actingAs($admin)->get('/caisse')->assertOk()->assertSee('Ousmane Barry')->assertDontSee('id="rechercheClient"', false);
    }

    public function test_grande_boutique_liste_allegee_et_recherche(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 50);
        // 320 clients anciens, sans achat récent
        DB::table('clients')->insert(collect(range(1, ClientCaisse::PRECHARGES_MAX + 20))->map(fn ($i) => [
            'boutique_id' => $b->id, 'nom' => 'Ancien'.$i, 'code' => 'CLI-A'.$i, 'telephone' => '62'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
            'created_at' => now()->subYear(), 'updated_at' => now()->subYear(),
        ])->all());
        // Un client qui doit de l'argent, et un client oublié perdu au milieu
        $debiteur = $this->dans($b, fn () => Client::create(['nom' => 'Camara', 'prenom' => 'Fanta', 'telephone' => '628112233']));
        $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $debiteur->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]],
            'mode' => 'especes', 'montant_recu' => 100_000]), $admin);
        DB::table('clients')->where('nom', 'Ancien7')->update(['nom' => 'Soumah', 'prenom' => 'Kadiatou', 'grossiste' => true, 'points' => 1500]);

        $page = $this->actingAs($admin)->get('/caisse')->assertOk()
            ->assertSee('id="rechercheClient"', false)->assertSee('Chercher parmi les 321 clients')
            ->assertSee('Fanta Camara')            // il a une dette : toujours dans la liste
            ->assertDontSee('Kadiatou Soumah');   // ancien client : il se cherche
        $this->assertLessThanOrEqual(ClientCaisse::PRECHARGES_MAX + 1, substr_count($page->getContent(), '<option value="') - 0);

        // Recherche : par nom ou par téléphone, avec ce que la caisse doit savoir
        $this->getJson('/caisse/clients?q=Soum')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.libelle', 'Kadiatou Soumah — 620000007 (grossiste)')->assertJsonPath('0.grossiste', 1)->assertJsonPath('0.points', 1500);
        $this->getJson('/caisse/clients?q=628112233')->assertJsonPath('0.du', 200_000);
        $this->getJson('/caisse/clients?q=a')->assertExactJson([]);   // 2 caractères au moins

        // Jamais les clients d'une autre boutique
        [$autre, $adminAutre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->actingAs($adminAutre->fresh())->getJson('/caisse/clients?q=Soum')->assertExactJson([]);
    }
}
