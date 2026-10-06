<?php

namespace Tests\Feature;

use App\Models\Acompte;
use App\Models\Devis;
use App\Models\Paiement;
use App\Models\Vente;
use App\Services\CaisseService;
use App\Services\Registre;
use App\Services\Tresorerie;
use Tests\TestCase;

class AcompteTest extends TestCase
{
    /** Devis de 4 × 300 000 = 1 200 000 GNF pour Mariama Diallo. */
    private function devis($b, $admin, array $extra = []): Devis
    {
        $p = $this->produit($b, [], 50);
        $this->actingAs($admin)->post('/devis', $extra + ['client_nom' => 'Mariama Diallo', 'lignes' => [['produit_id' => $p->id, 'quantite' => 4]]])
            ->assertSessionHasNoErrors();

        return $this->dans($b, fn () => Devis::latest('id')->first());
    }

    private function especesAttendues($b, $admin): int
    {
        return $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now())['especes_theoriques']);
    }

    public function test_acompte_encaisse_le_jour_du_versement_puis_deduit_a_la_livraison(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $d = $this->devis($b, $admin);

        $this->post("/devis/{$d->id}/acomptes", ['montant' => '500 000', 'mode' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Reste à payer à la livraison : 700 000 GNF'))->assertSessionHas('recu_acompte');
        $this->assertSame(500_000, $d->fresh()->acompte);
        $this->assertSame(500_000, $this->especesAttendues($b, $admin), 'l\'avance est dans le tiroir dès aujourd\'hui');

        $a = $this->dans($b, fn () => Acompte::first());
        $this->get("/devis/{$d->id}")->assertSee('Acompte déjà versé')->assertSee('700 000 GNF')->assertSee('Encaisser un acompte');
        $this->get("/acomptes/{$a->id}/recu")->assertOk()->assertSee("REÇU D'ACOMPTE")->assertSee('Mariama Diallo')->assertSee('700 000 GNF');
        $this->get('/devis')->assertSee('acompte 500 000 GNF');
        $this->get("/devis/{$d->id}/proforma")->assertOk();

        // Jamais plus que le montant du devis
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '800 000', 'mode' => 'orange_money'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'entre 1 et 700 000 GNF'));

        // Livraison : le client paie le reste en Orange Money
        $this->post("/devis/{$d->id}/vente", ['mode' => 'orange_money'])->assertRedirect();
        $v = $this->dans($b, fn () => Vente::with('paiements')->first());
        $this->assertSame(1_200_000, $v->total_ttc);
        $this->assertSame(1_200_000, $v->montant_paye);
        $this->assertEquals(['acompte' => 500_000, 'orange_money' => 700_000], $v->paiements->pluck('montant', 'mode')->all());
        $this->assertSame('converti', $d->fresh()->statut);
        // Pas de double comptage : les espèces du tiroir n'ont pas bougé à la livraison
        $this->assertSame(500_000, $this->especesAttendues($b, $admin));
        $bilan = $this->dans($b, fn () => app(CaisseService::class)->bilan($admin, now()));
        $this->assertArrayNotHasKey('acompte', $bilan['encaisse']);
        $this->assertTrue($this->dans($b, fn () => app(Registre::class)->verifier($b)['intact']));

        // Trésorerie : l'acompte apparaît une seule fois, en caisse
        $lignes = $this->dans($b, fn () => app(Tresorerie::class)->lignes('caisse', now()->subDay(), now()->addMinute()));
        $this->assertSame(500_000, (int) $lignes->sum('entree'));
    }

    public function test_devis_annule_l_acompte_est_rembourse(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $d = $this->devis($b, $admin);
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '300 000', 'mode' => 'especes']);
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '200 000', 'mode' => 'orange_money', 'reference' => 'OM123']);
        $this->assertSame(500_000, $d->fresh()->acompte);

        $this->post("/devis/{$d->id}/annuler", ['mode_remboursement' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Rendez 500 000 GNF'));
        $d->refresh();
        $this->assertSame(['annule', 0], [$d->statut, $d->acompte]);
        // 300 000 entrés, 500 000 rendus en espèces : −200 000 dans le tiroir (les 200 000 OM sont arrivés sur le téléphone)
        $this->assertSame(-200_000, $this->especesAttendues($b, $admin));
        $this->get("/devis/{$d->id}")->assertSee('500 000 GNF rendu');
    }

    public function test_acompte_suit_le_devis_recree_et_revient_si_la_vente_est_annulee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $d = $this->devis($b, $admin);
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '400 000', 'mode' => 'especes']);

        // Expiré puis recréé au prix du jour
        $d->update(['valable_jusqu_au' => now()->subDay()]);
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '1 000', 'mode' => 'especes'])->assertSessionHas('erreur');
        $this->post("/devis/{$d->id}/renouveler")->assertRedirect();
        $nouveau = $this->dans($b, fn () => Devis::latest('id')->first());
        $this->assertNotSame($d->id, $nouveau->id);
        $this->assertSame([400_000, 0], [$nouveau->acompte, $d->fresh()->acompte]);
        $this->assertSame(1, $this->dans($b, fn () => Acompte::where('devis_id', $nouveau->id)->count()));

        // Livré puis vente annulée : la commande redevient « en cours » avec son acompte
        $this->post("/devis/{$nouveau->id}/vente", ['mode' => 'especes'])->assertRedirect();
        $v = $this->dans($b, fn () => Vente::first());
        $this->assertSame(1_200_000, $this->especesAttendues($b, $admin));
        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Erreur de saisie'])->assertSessionHas('succes');
        $nouveau->refresh();
        $this->assertSame(['en_cours', null, 400_000], [$nouveau->statut, $nouveau->vente_id, $nouveau->acompte]);
        $this->assertSame(400_000, $this->especesAttendues($b, $admin), 'seuls les 800 000 payés à la livraison sont rendus ; l\'acompte reste dû sur la commande');
    }

    public function test_acompte_exige_un_client_et_une_caisse_ouverte(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $d = $this->devis($b, $admin, ['client_nom' => '']);
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '10 000', 'mode' => 'orange_money'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'client'));
        $this->get("/devis/{$d->id}")->assertSee('Indiquer le client')->assertDontSee('Encaisser un acompte');

        // Le vendeur indique le client, puis l'acompte passe
        $this->post("/devis/{$d->id}/client", [])->assertSessionHasErrors('client_nom');
        $this->post("/devis/{$d->id}/client", ['client_nom' => 'Ibrahima Sow', 'client_telephone' => '622 11 22 33'])->assertSessionHas('succes');
        $this->assertSame(['Ibrahima Sow', '622 11 22 33'], [$d->fresh()->client_nom, $d->fresh()->client_telephone]);
        $this->get("/devis/{$d->id}")->assertSee('Encaisser un acompte')->assertDontSee('Indiquer le client');
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '10 000', 'mode' => 'orange_money'])->assertSessionHas('succes');

        $d2 = $this->devis($b, $admin);
        $this->post('/caisse/cloture', ['especes_comptees' => '0'])->assertRedirect();
        $this->post("/devis/{$d2->id}/acomptes", ['montant' => '10 000', 'mode' => 'especes'])->assertSessionHas('erreur');
        $this->post("/devis/{$d2->id}/acomptes", ['montant' => '10 000', 'mode' => 'orange_money'])->assertSessionHas('succes');
        $this->assertSame(0, $this->dans($b, fn () => Paiement::count()));
    }
}
