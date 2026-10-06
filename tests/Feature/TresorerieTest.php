<?php

namespace Tests\Feature;

use App\Models\Depense;
use App\Services\CaisseService;
use App\Services\Tresorerie;
use App\Services\VenteService;
use Tests\TestCase;

/** Trésorerie par compte : soldes calculés, transferts avec frais, apports/retraits, constats, lien avec la caisse. */
class TresorerieTest extends TestCase
{
    private function soldes($b)
    {
        return collect($this->dans($b, fn () => app(Tresorerie::class)->soldes()))->map(fn ($s) => $s['solde']);
    }

    public function test_soldes_par_compte_et_transferts(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20); // vendu 300 000
        $vs = app(VenteService::class);
        $this->dans($b, fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $this->dans($b, fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'orange_money']), $admin);
        $annulee = $this->dans($b, fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'mtn_momo']), $admin);
        $this->dans($b, fn () => $vs->annuler($annulee, 'Erreur'), $admin);
        $this->dans($b, fn () => Depense::create(['motif' => 'Transport', 'montant' => 50_000, 'mode' => 'especes', 'date_depense' => now()->toDateString(), 'user_id' => $admin->id]));

        $s = $this->soldes($b);
        $this->assertSame(550_000, $s['caisse']);
        $this->assertSame(300_000, $s['orange_money']);
        $this->assertSame(0, $s['mtn_momo'], 'vente annulée : argent rendu');

        // Versement de 500 000 à la banque, puis retrait Orange Money de 200 000 avec 4 000 de frais
        $this->actingAs($admin)->post('/tresorerie', ['type' => 'transfert', 'compte_source' => 'caisse', 'compte_destination' => 'banque', 'montant' => '500 000'])
            ->assertSessionHas('succes');
        $this->post('/tresorerie', ['type' => 'transfert', 'compte_source' => 'orange_money', 'compte_destination' => 'caisse', 'montant' => 200_000, 'frais' => 4_000])
            ->assertSessionHas('succes');
        $s = $this->soldes($b);
        $this->assertSame(250_000, $s['caisse']);
        $this->assertSame(500_000, $s['banque']);
        $this->assertSame(96_000, $s['orange_money']);

        // La caisse du caissier suit : 600 000 − 50 000 − 500 000 + 200 000
        $this->assertSame(250_000, $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']));

        // Pas plus que ce qu'on a
        $this->post('/tresorerie', ['type' => 'retrait', 'compte_source' => 'banque', 'montant' => 900_000])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'insuffisant'));
        $this->post('/tresorerie', ['type' => 'transfert', 'compte_source' => 'banque', 'compte_destination' => 'banque', 'montant' => 1_000])
            ->assertSessionHas('erreur');

        // Frais financiers dans le compte de résultat
        $this->get('/rapports/resultat/ecran')->assertSee('Frais financiers')->assertSee('-4 000 GNF');
        $this->get('/tresorerie')->assertOk()->assertSee('Orange Money')->assertSee('96 000 GNF');
        $this->get('/tresorerie/caisse')->assertOk()->assertSee('Solde à la fin')->assertSee('250 000 GNF');
    }

    public function test_constat_de_solde_et_apport_retrait(): void
    {
        [$b, $admin] = $this->creerBoutique();
        // Le commerçant démarre : il a 2 000 000 sur Orange Money (argent d'avant le logiciel)
        $this->actingAs($admin)->post('/tresorerie', ['type' => 'constat', 'compte_destination' => 'orange_money', 'montant' => 2_000_000])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, '+2 000 000'));
        $this->assertSame(2_000_000, $this->soldes($b)['orange_money']);

        $this->post('/tresorerie', ['type' => 'retrait', 'compte_source' => 'orange_money', 'montant' => 300_000, 'motif' => 'Frais de scolarité'])->assertSessionHas('succes');
        $this->post('/tresorerie', ['type' => 'apport', 'compte_destination' => 'banque', 'montant' => 1_000_000])->assertSessionHas('succes');
        $s = $this->soldes($b);
        $this->assertSame(1_700_000, $s['orange_money']);
        $this->assertSame(1_000_000, $s['banque']);

        // Nouveau constat : 1 690 000 lus sur le téléphone → écart de −10 000, le solde repart du réel
        $this->post('/tresorerie', ['type' => 'constat', 'compte_destination' => 'orange_money', 'montant' => 1_690_000])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, '−10 000'));
        $this->assertSame(1_690_000, $this->soldes($b)['orange_money']);
    }

    public function test_reserve_aux_personnes_autorisees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, function () use ($b) {
            $role = \App\Models\Role::where('nom', 'Vendeur')->first();

            return \App\Models\User::create(['boutique_id' => $b->id, 'role_id' => $role->id, 'prenom' => 'V', 'nom' => 'Vendeur',
                'email' => 'vendeur@test.gn', 'password' => 'secret123', 'actif' => true]);
        });
        $this->actingAs($vendeur)->get('/tresorerie')->assertForbidden();
    }
}
