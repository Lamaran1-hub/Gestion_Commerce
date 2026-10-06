<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\Registre;
use App\Services\VenteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Fiches clients en double : repérées, fusionnées (achats, crédits, points, avoirs), sans casser le registre anti-fraude. */
class FusionClientsTest extends TestCase
{
    private function vendre($b, $admin, Client $c, int $recu): Vente
    {
        $p = $this->produit($b, [], 20);   // 300 000 l'unité

        return $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]],
            'mode' => 'especes', 'montant_recu' => $recu]), $admin);
    }

    public function test_fusion_regroupe_tout_et_le_registre_reste_intact(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $ancienne = $this->dans($b, fn () => Client::create(['nom' => 'Diallo', 'prenom' => 'Mariama', 'telephone' => '620112233']));
        $doublon = $this->dans($b, fn () => Client::create(['nom' => 'DIALLO', 'prenom' => 'Mariama ', 'email' => 'mariama@exemple.gn']));
        $this->vendre($b, $admin, $ancienne, 100_000);                      // doit 200 000
        $vDoublon = $this->vendre($b, $admin, $doublon, 250_000);           // doit 50 000
        $this->dans($b, fn () => $doublon->forceFill(['points' => 1200, 'avoir' => 30_000])->save());

        // Repérées comme doublons (même nom, accents et majuscules ignorés)
        $this->actingAs($admin)->get('/clients/doublons')->assertOk()->assertSee('Mariama Diallo')->assertSee($doublon->code)->assertSee('200 000');
        $this->get('/clients')->assertSee('Doublons');

        $this->post('/clients/fusion', ['garde_id' => $ancienne->id, 'doublons' => [$doublon->id]])
            ->assertRedirect(route('clients.show', $ancienne))->assertSessionHas('succes');

        $g = $ancienne->fresh();
        $this->assertSame(250_000, $g->soldeDu(), 'les deux crédits sont sur la même fiche');
        $this->assertSame([1200, 30_000, 'mariama@exemple.gn', '620112233'], [$g->points, $g->avoir, $g->email, $g->telephone]);
        $this->assertSame($g->id, $vDoublon->fresh()->client_id);
        $this->assertSame(2, $this->dans($b, fn () => \App\Models\Paiement::where('client_id', $g->id)->count()));
        $this->assertSoftDeleted('clients', ['id' => $doublon->id]);
        $this->get("/clients/{$g->id}")->assertOk()->assertSee('250 000');

        // Registre : la fusion est signée, la vérification ne voit aucune falsification
        $verif = $this->dans($b, fn () => app(Registre::class)->verifier($b));
        $this->assertTrue($verif['intact'], implode(' | ', $verif['anomalies'] ?? []));

        // Mais un changement de client fait hors du logiciel reste détecté
        $autre = $this->dans($b, fn () => Client::create(['nom' => 'Complice']));
        DB::table('ventes')->where('id', $vDoublon->id)->update(['client_id' => $autre->id]);
        $verif = $this->dans($b, fn () => app(Registre::class)->verifier($b));
        $this->assertFalse($verif['intact']);
        $this->assertStringContainsString('client modifié', implode(' | ', $verif['anomalies']));
    }

    public function test_doublons_par_telephone_et_droits(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->dans($b, fn () => Client::create(['nom' => 'Barry', 'telephone' => '+224 628 11 22 33']));
        $this->dans($b, fn () => \Illuminate\Support\Facades\DB::table('clients')->insert(['boutique_id' => $b->id, 'nom' => 'Ousmane B.', 'code' => 'CLI-X1',
            'telephone' => '628112233', 'created_at' => now(), 'updated_at' => now()]));
        $this->actingAs($admin)->get('/clients/doublons')->assertSee('Ousmane B.')->assertSee('Barry');

        // Un vendeur ne peut ni voir ni fusionner (la fusion déplace des dettes)
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $this->actingAs($vendeur)->get('/clients/doublons')->assertForbidden();
        $this->post('/clients/fusion', ['garde_id' => 1, 'doublons' => [2]])->assertForbidden();
        $this->get('/clients')->assertDontSee('Doublons');

        // Jamais la fiche d'une autre boutique
        [$autre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $etrangere = $this->dans($autre, fn () => Client::create(['nom' => 'Étranger']));
        $mienne = $this->dans($b, fn () => Client::first());
        $this->actingAs($admin)->post('/clients/fusion', ['garde_id' => $mienne->id, 'doublons' => [$etrangere->id]])->assertNotFound();
        $this->assertNotSoftDeleted('clients', ['id' => $etrangere->id]);
    }
}
