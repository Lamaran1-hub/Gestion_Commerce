<?php

namespace Tests\Feature;

use App\Models\Approvisionnement;
use App\Models\CommandeFournisseur;
use App\Models\Fournisseur;
use App\Services\Reapprovisionnement;
use Tests\TestCase;

class CommandeFournisseurTest extends TestCase
{
    public function test_commande_puis_receptions_partielle_et_complete(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci', 'telephone' => '622000222']));
        $p = $this->produit($b, ['fournisseur_id' => $f->id, 'seuil_alerte' => 10, 'prix_achat' => 250_000], 2);

        // Proposé à la commande (sous le seuil)
        $this->assertSame(1, $this->dans($b, fn () => app(Reapprovisionnement::class)->suggestions()->count()));
        $this->actingAs($admin)->get('/stock/a-commander')->assertSee('Enregistrer la commande');

        $this->post('/commandes-fournisseur', ['fournisseur_id' => $f->id, 'quantites' => [$p->id => 20], 'livraison_prevue_le' => now()->addDays(2)->toDateString()])
            ->assertRedirect()->assertSessionHas('succes');
        $c = $this->dans($b, fn () => CommandeFournisseur::with('lignes')->first());
        $this->assertSame(['envoyee', 5_000_000], [$c->statut, $c->total_estime]);
        $this->assertEquals(2, $p->fresh()->stock, 'une commande ne bouge pas le stock');

        // Déjà commandé : plus proposé
        $this->assertSame(0, $this->dans($b, fn () => app(Reapprovisionnement::class)->suggestions()->count()));
        $this->get('/stock/a-commander')->assertSee('1 commande(s) déjà passée(s)');

        $this->get("/commandes-fournisseur/{$c->id}")->assertOk()->assertSee('Réceptionner')->assertSee('Envoyer au fournisseur');
        $this->get("/commandes-fournisseur/{$c->id}/bon")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/approvisionnements/create?commande={$c->id}")->assertOk()->assertSee($c->numero)->assertSee('ajouter(p, 20', false);
        $this->get('/commandes-fournisseur')->assertSee($c->numero);

        // 1re livraison : 12 sur 20
        $recevoir = fn ($q) => $this->post('/approvisionnements', ['fournisseur_id' => $f->id, 'commande_fournisseur_id' => $c->id, 'date_appro' => now()->toDateString(),
            'reglement' => 'credit', 'lignes' => [['produit_id' => $p->id, 'quantite' => $q, 'prix_achat_unitaire' => '250 000']]]);
        $recevoir(12)->assertSessionHasNoErrors();
        $c->refresh();
        $this->assertSame('partielle', $c->statut);
        $this->assertEquals(12, $c->lignes->first()->fresh()->quantite_recue);
        $this->assertSame($c->id, $this->dans($b, fn () => Approvisionnement::first()->commande_fournisseur_id));
        $this->assertEquals(14, $p->fresh()->stock);
        $this->get("/approvisionnements/create?commande={$c->id}")->assertSee('ajouter(p, 8', false);

        // 2e livraison : le reste
        $recevoir(8)->assertSessionHasNoErrors();
        $this->assertSame('recue', $c->fresh()->statut);
        $this->get("/commandes-fournisseur/{$c->id}")->assertDontSee('btnReceptionner');
        $recevoir(1)->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Reçue'));
    }

    public function test_solder_annuler_et_autre_fournisseur(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Sodeci']));
        $autre = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Autre']));
        $p = $this->produit($b, [], 0);

        $this->actingAs($admin)->post('/commandes-fournisseur', ['fournisseur_id' => $f->id, 'quantites' => [$p->id => 0]])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'au moins une quantité'));
        $this->post('/commandes-fournisseur', ['fournisseur_id' => $f->id, 'quantites' => [$p->id => 10]]);
        $c = $this->dans($b, fn () => CommandeFournisseur::first());

        // Réception chez un autre fournisseur : refusée
        $this->post('/approvisionnements', ['fournisseur_id' => $autre->id, 'commande_fournisseur_id' => $c->id, 'date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 4, 'prix_achat_unitaire' => '1 000']]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'autre fournisseur'));
        $this->assertSame(0, $this->dans($b, fn () => Approvisionnement::count()), 'rien d\'enregistré');

        // Sans réception : annulée
        $this->post("/commandes-fournisseur/{$c->id}/solder")->assertSessionHas('succes');
        $this->assertSame('annulee', $c->fresh()->statut);

        // Avec une réception partielle : soldée
        $this->post('/commandes-fournisseur', ['fournisseur_id' => $f->id, 'quantites' => [$p->id => 10]]);
        $c2 = $this->dans($b, fn () => CommandeFournisseur::latest('id')->first());
        $this->post('/approvisionnements', ['fournisseur_id' => $f->id, 'commande_fournisseur_id' => $c2->id, 'date_appro' => now()->toDateString(),
            'lignes' => [['produit_id' => $p->id, 'quantite' => 4, 'prix_achat_unitaire' => '1 000']]])->assertSessionHasNoErrors();
        $this->post("/commandes-fournisseur/{$c2->id}/solder")->assertSessionHas('succes');
        $this->assertSame('soldee', $c2->fresh()->statut);
        $this->assertSame(0, $this->dans($b, fn () => app(\App\Services\CommandesFournisseur::class)->enCommande()->count()));

        // Isolation
        [, $admin2] = $this->creerBoutique('Autre boutique', 'autre@test.gn');
        $this->actingAs($admin2)->get("/commandes-fournisseur/{$c2->id}")->assertNotFound();
    }
}
