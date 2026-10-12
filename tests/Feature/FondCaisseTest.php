<?php

namespace Tests\Feature;

use App\Models\ClotureCaisse;
use App\Models\FondCaisse;
use App\Models\JournalActivite;
use App\Models\Role;
use App\Models\User;
use App\Services\Registre;
use Tests\TestCase;

/** Fond de caisse : déclaré le matin, compté dans les espèces attendues, verrouillé après la première vente. */
class FondCaisseTest extends TestCase
{
    public function test_fond_du_matin_et_laisse_pour_demain(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(8, 0));
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $p = $this->produit($b, [], 20);   // 300 000 l'unité

        // Le matin : la caisse demande le fond
        $this->actingAs($vendeur)->get('/caisse')->assertSee('id="fondCaisse"', false)->assertSee('combien de monnaie dans le tiroir');
        $this->post('/caisse/fond', ['montant' => '150 000'])->assertSessionHas('succes');
        $this->get('/caisse')->assertDontSee('id="fondCaisse"', false);
        // Avant toute vente, il peut encore le corriger
        $this->post('/caisse/fond', ['montant' => '200 000'])->assertSessionHas('succes');
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes'])->assertSessionHas('succes');
        // Après la première vente : verrouillé pour le vendeur
        $this->post('/caisse/fond', ['montant' => '50 000'])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'seul l\'administrateur'));
        $this->assertSame(200_000, $this->dans($b, fn () => FondCaisse::sole())->montant);
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', 'Fond de caisse corrigé : 150 000 GNF → 200 000 GNF%')->exists()));

        // Clôture : 200 000 de fond + 300 000 de vente = 500 000 attendus ; il laisse 100 000 pour demain
        $this->get('/caisse/cloture')->assertSee('Fond de caisse du matin')->assertSee('500 000 GNF');
        $this->post('/caisse/cloture', ['especes_comptees' => '500 000', 'laisse_en_caisse' => '600 000'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'plus que les espèces comptées'));
        $this->post('/caisse/cloture', ['especes_comptees' => '500 000', 'laisse_en_caisse' => '100 000'])->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->sole();
        $this->assertSame([200_000, 500_000, 0, 100_000], [$c->fond_caisse, $c->especes_theoriques, $c->ecart, $c->laisse_en_caisse]);
        $this->get("/clotures/{$c->id}")->assertSee('+ 200 000 GNF')->assertSee('Laissé dans le tiroir pour demain')->assertSee('400 000 GNF');

        // Le lendemain : les 100 000 laissés sont proposés ; un fond différent est signalé sur le rapport Z
        $this->travelTo(now()->addDay()->setTime(8, 0));
        $this->flushSession();
        $this->actingAs($vendeur->fresh())->get('/caisse')->assertSee('Laissé hier soir : 100 000 GNF')->assertSee('value="100000"', false);
        $this->post('/caisse/fond', ['montant' => '60 000'])->assertSessionHas('succes');
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', '%laissé la veille : 100 000 GNF%')->exists()));
        $this->post('/caisse/cloture', ['especes_comptees' => '60 000'])->assertRedirect();
        $c2 = ClotureCaisse::withoutGlobalScope('boutique')->latest('id')->first();
        $this->get("/clotures/{$c2->id}")->assertSee('Différent de ce qui a été laissé dans le tiroir la veille : 100 000 GNF');

        $this->assertTrue(app(Registre::class)->verifier($b->fresh())['intact']);
    }

    public function test_l_administrateur_corrige_apres_les_ventes(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $this->actingAs($admin)->post('/caisse/fond', ['montant' => '100 000']);
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']);
        $this->post('/caisse/fond', ['montant' => '120 000'])->assertSessionHas('succes');
        $this->assertSame(120_000, $this->dans($b, fn () => FondCaisse::sole())->montant);

        // Sans fond déclaré, rien ne change par rapport à avant (fond = 0)
        [$b2, $admin2] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->flushSession();
        $this->actingAs($admin2->fresh())->get('/caisse/cloture')->assertSee('non déclaré (0)');
        $this->post('/caisse/cloture', ['especes_comptees' => '0'])->assertRedirect();
        $this->assertSame(0, ClotureCaisse::withoutGlobalScope('boutique')->where('boutique_id', $b2->id)->sole()->fond_caisse);

        // Caisse clôturée : plus de fond
        $this->post('/caisse/fond', ['montant' => '1 000'])->assertSessionHas('erreur');
    }
}
