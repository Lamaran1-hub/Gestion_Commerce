<?php

namespace Tests\Feature;

use App\Models\ClotureCaisse;
use App\Models\Paiement;
use App\Models\User;
use App\Models\Role;
use App\Services\Registre;
use Tests\TestCase;

/** Espèces en devises : conversion au taux de la boutique, monnaie rendue en GNF, devises comptées à part à la clôture. */
class DevisesTest extends TestCase
{
    public function test_parametres_des_taux(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1f7a5a',
            'taux_devises' => ['USD' => '8600', 'EUR' => '', 'XOF' => '14.5', 'GBP' => '11000']])->assertSessionHasNoErrors();
        $this->assertSame(['USD' => 8600.0, 'XOF' => 14.5], $b->fresh()->tauxDevises(), 'devise vide refusée, devise inconnue ignorée');
        $this->get('/parametres')->assertSee('Espèces en devises')->assertSee('value="8600"', false);
        // Sans le champ (formulaire partiel), les taux ne bougent pas
        $this->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1f7a5a']);
        $this->assertSame(['USD' => 8600.0, 'XOF' => 14.5], $b->fresh()->tauxDevises());
    }

    public function test_vente_en_dollars_et_cloture(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['taux_devises' => ['USD' => 8600]]);
        $p = $this->produit($b, [], 20);   // 300 000 l'unité
        $vente = ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes'];

        $this->actingAs($admin)->get('/caisse')->assertSee('id="devise"', false)->assertSee('En $ (USD)');
        $this->post('/ventes', $vente)->assertSessionHas('succes');   // une vente en GNF
        // 40 $ × 8 600 = 344 000 : le montant reçu envoyé par le navigateur est ignoré, le taux vient de la boutique
        $this->post('/ventes', $vente + ['devise' => 'USD', 'montant_devise' => '40', 'montant_recu' => '999 999'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Reçu 40 $ : rendez 44 000 GNF au client.'));
        $paiement = $this->dans($b, fn () => Paiement::whereNotNull('devise')->sole());
        $this->assertSame([300_000, 'USD', 40.0, 8600.0, 344_000], [$paiement->montant, $paiement->devise, $paiement->montant_devise, $paiement->taux_devise, $paiement->contre_valeur]);
        $this->get('/ventes/'.$paiement->vente_id)->assertSee('Reçu 40 $ (1 $ = 8 600 GNF) · rendu 44 000 GNF');

        // Refus : devise sans taux, devise hors espèces, montant manquant
        $this->post('/ventes', $vente + ['devise' => 'EUR', 'montant_devise' => '50'])->assertSessionHasErrors('devise');
        $this->post('/ventes', ['mode' => 'orange_money', 'devise' => 'USD', 'montant_devise' => '50'] + $vente)->assertSessionHasErrors('devise');
        $this->post('/ventes', $vente + ['devise' => 'USD'])->assertSessionHasErrors('montant_devise');
        $this->assertSame(2, $this->dans($b, fn () => Paiement::count()));

        // Clôture : GNF attendus = 300 000 + 300 000 − 344 000 (les dollars ne sont pas des GNF) ; 40 $ comptés à part
        $this->get('/caisse/cloture')->assertSee('256 000 GNF')->assertSee('Dollar américain comptés')->assertSee('attendus : 40 $');
        $this->post('/caisse/cloture', ['especes_comptees' => '256 000', 'devises_comptees' => ['USD' => '30']])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, '40 $ attendus, 30 $ comptés'));
        $this->post('/caisse/cloture', ['especes_comptees' => '256 000', 'devises_comptees' => ['USD' => '40']])->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->sole();
        $this->assertSame([0, null], [$c->ecart, $c->motif_ecart]);
        $this->assertEquals(['USD' => ['attendu' => 40, 'compte' => 40]], $c->devises);
        $this->get("/clotures/{$c->id}")->assertSee('Dollar américain : attendu 40 $')->assertSee('compté 40 $');

        // Le registre inaltérable reste vérifiable (paiement en devises et clôture signés)
        $this->assertTrue(app(Registre::class)->verifier($b->fresh())['intact']);
    }

    public function test_ecart_en_devises_avec_motif(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['taux_devises' => ['EUR' => 9400.5]]);
        $p = $this->produit($b, ['prix_vente' => 940_050], 5);
        $this->actingAs($admin)->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'devise' => 'EUR', 'montant_devise' => '100,00'])
            ->assertSessionHasErrors('montant_devise');   // la caisse envoie un point décimal
        $this->post('/ventes', ['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes', 'devise' => 'EUR', 'montant_devise' => '100'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Reçu 100 €.'));

        $this->post('/caisse/cloture', ['especes_comptees' => '0', 'devises_comptees' => ['EUR' => '90'], 'motif' => 'Billet douteux'])->assertRedirect();
        $c = ClotureCaisse::withoutGlobalScope('boutique')->sole();
        $this->assertSame('Billet douteux', $c->motif_ecart);
        $this->get("/clotures/{$c->id}")->assertSee('attendu 100 €')->assertSee('compté 90 €');
        $this->assertTrue(app(Registre::class)->verifier($b->fresh())['intact']);
    }
}
