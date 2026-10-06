<?php

namespace Tests\Feature;

use App\Models\Devis;
use App\Notifications\CommandeVitrine;
use App\Services\VenteService;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** TVA à plusieurs taux (produits exonérés) et vitrine en ligne avec commande par WhatsApp. */
class TvaEtVitrineTest extends TestCase
{
    public function test_vente_mixte_exonere_et_taux_normal_avec_remise(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $normal = $this->produit($b, ['designation' => 'Savon', 'prix_vente' => 100_000, 'prix_achat' => 50_000]);
        $exonere = $this->produit($b, ['designation' => 'Riz local', 'prix_vente' => 100_000, 'prix_achat' => 50_000, 'taux_tva' => 0]);

        $v = $this->dans($b->fresh(), fn () => app(VenteService::class)->creer(['lignes' => [
            ['produit_id' => $normal->id, 'quantite' => 1], ['produit_id' => $exonere->id, 'quantite' => 1]],
            'remise' => 20_000, 'mode' => 'especes']), $admin);

        // Remise répartie 10 000 / 10 000 ; TVA 18 % sur 90 000 seulement
        $this->assertSame(16_200, $v->total_tva);
        $this->assertSame(196_200, $v->total_ttc);
        $this->assertEquals([18.0, 0.0], $v->lignes->sortBy('id')->pluck('taux_tva')->map(fn ($t) => (float) $t)->all());
        $ventilation = $v->ventilationTva();
        $this->assertSame(['base' => 90_000, 'tva' => 16_200], array_intersect_key($ventilation['18.00'], ['base' => 1, 'tva' => 1]));
        $this->assertSame(0, $ventilation['0.00']['tva']);

        $this->actingAs($admin)->get("/ventes/{$v->id}/facture")->assertOk();
        $this->get("/ventes/{$v->id}/recu")->assertOk()->assertSee('Exonéré');

        // Retour du produit exonéré : on rembourse sa part nette de remise, sans TVA
        $ligne = $v->lignes->firstWhere('produit_id', $exonere->id);
        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$ligne->id => 1], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes', fn ($m) => str_contains($m, 'Remboursez 90 000 GNF'));
        $this->assertSame(16_200, $v->fresh()->total_tva);

        // Rapport Z : le remboursement apparaît à part, le net des espèces reste juste
        $bilan = $this->dans($b, fn () => app(\App\Services\CaisseService::class)->bilan($admin, now()));
        $this->assertSame(196_200, $bilan['encaisse']['especes']);
        $this->assertSame(90_000, $bilan['rembourse']['especes']);
        $this->assertSame(106_200, $bilan['especes_theoriques']);
    }

    public function test_la_caisse_propose_le_scan_par_camera_et_les_taux_par_produit(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $this->produit($b, ['designation' => 'Riz local', 'taux_tva' => 0]);

        $this->actingAs($admin)->get('/caisse')->assertOk()
            ->assertSee('id="scanCamera"', false)->assertSee('BarcodeDetector', false)
            ->assertSee('"tva":0', false);
    }

    public function test_le_retour_complet_d_un_taux_le_retire_de_la_ventilation(): void
    {
        $v = \App\Support\Tva::ventiler([['total' => 90_000, 'taux' => 18], ['total' => 0, 'taux' => 0]], 90_000, 9_000);
        $this->assertSame(['18.00'], array_keys($v['ventilation']));
        $this->assertSame(14_580, $v['tva']);
    }

    public function test_le_taux_du_produit_se_regle_depuis_sa_fiche(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $p = $this->produit($b);
        $base = ['designation' => $p->designation, 'unite' => 'sac', 'prix_achat' => 250_000, 'prix_vente' => 300_000];

        $this->actingAs($admin)->put("/produits/{$p->id}", $base + ['regime_tva' => 'exonere'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $p->fresh()->taux_tva);
        $this->put("/produits/{$p->id}", $base + ['regime_tva' => 'autre', 'taux_tva_autre' => 10])->assertSessionHasNoErrors();
        $this->assertSame(10.0, $p->fresh()->taux_tva);
        $this->put("/produits/{$p->id}", $base + ['regime_tva' => 'normal'])->assertSessionHasNoErrors();
        $this->assertNull($p->fresh()->taux_tva);
    }

    public function test_vitrine_fermee_ou_hors_formule_introuvable(): void
    {
        [$b] = $this->creerBoutique();
        $this->get("/vitrine/{$b->slug}")->assertNotFound();   // pas encore publiée

        [$petite] = $this->creerBoutique('Petite', 'petite@test.gn', false); // Démarrage, sans la vitrine
        $petite->update(['vitrine_active' => true]);
        $this->get("/vitrine/{$petite->slug}")->assertNotFound();
    }

    public function test_vitrine_publiee_montre_seulement_les_produits_proposes(): void
    {
        [$b] = $this->creerBoutique();
        $b->update(['vitrine_active' => true, 'vitrine_message' => 'Livraison gratuite à Kaloum']);
        $this->produit($b, ['designation' => 'Huile 5 L', 'prix_vente' => 95_000, 'prix_achat' => 80_000]);
        $this->produit($b, ['designation' => 'Article caché', 'en_vitrine' => false]);

        $this->get("/vitrine/{$b->slug}")->assertOk()->assertSee('Huile 5 L')->assertDontSee('Article caché')
            ->assertSee('Livraison gratuite à Kaloum');
    }

    public function test_commande_en_ligne_cree_un_devis_vitrine_sans_toucher_au_stock(): void
    {
        Notification::fake();
        [$b, $admin] = $this->creerBoutique();
        $b->update(['vitrine_active' => true, 'telephone' => '622 00 00 00']);
        $p = $this->produit($b, [], 10);
        $cache = $this->produit($b, ['designation' => 'Hors ligne', 'en_vitrine' => false], 10);

        $this->post("/vitrine/{$b->slug}/commande", $this->humain() + ['nom' => 'Mariama', 'telephone' => '620 11 22 33', 'note' => 'Livrer à Matam',
            'lignes' => [['produit_id' => $p->id, 'quantite' => 2], ['produit_id' => $cache->id, 'quantite' => 1]]])
            ->assertRedirect("/vitrine/{$b->slug}/merci");

        $d = Devis::withoutGlobalScope('boutique')->with('lignes')->sole();
        $this->assertSame('vitrine', $d->origine);
        $this->assertSame($b->id, $d->boutique_id);
        $this->assertSame('620 11 22 33', $d->client_telephone);
        $this->assertCount(1, $d->lignes, 'Le produit retiré de la vitrine est ignoré');
        $this->assertSame(600_000, $d->total_ttc);
        $this->assertEquals(10, $p->fresh()->stock, 'Aucun stock réservé avant confirmation');
        Notification::assertSentTo($admin, CommandeVitrine::class);

        $this->get("/vitrine/{$b->slug}/merci")->assertOk()->assertSee($d->numero)->assertSee('wa.me/224622000000', false);

        $this->actingAs($admin)->get('/devis')->assertOk()->assertSee('Vitrine');
        $this->get("/devis/{$d->id}")->assertOk()->assertSee('Commande vitrine')->assertSee('620 11 22 33')->assertSee('Confirmer sur WhatsApp');
        $this->get('/tableau-de-bord')->assertOk()->assertSee('1 commande(s) en ligne à confirmer');
        $this->get('/devis?etat=en_ligne')->assertOk()->assertSee($d->numero)->assertSee('Commandes en ligne à traiter (1)');

        // Une fois annulée, la commande ne compte plus parmi celles à traiter
        $d->update(['statut' => 'annule']);
        $this->get('/tableau-de-bord')->assertOk()->assertDontSee('commande(s) en ligne à confirmer');
    }

    public function test_commande_avec_seulement_des_produits_retires_est_refusee(): void
    {
        [$b] = $this->creerBoutique();
        $b->update(['vitrine_active' => true]);
        $cache = $this->produit($b, ['en_vitrine' => false]);

        $this->from("/vitrine/{$b->slug}")->post("/vitrine/{$b->slug}/commande", $this->humain() + ['nom' => 'A', 'telephone' => '620112233',
            'lignes' => [['produit_id' => $cache->id, 'quantite' => 1]]])->assertRedirect("/vitrine/{$b->slug}")->assertSessionHasErrors('lignes');
        $this->assertSame(0, Devis::withoutGlobalScope('boutique')->count());
    }

    public function test_commandes_en_ligne_limitees_contre_les_abus(): void
    {
        [$b] = $this->creerBoutique();
        $b->update(['vitrine_active' => true]);
        $p = $this->produit($b);
        $donnees = ['nom' => 'Test', 'telephone' => '620112233', 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]];

        for ($i = 0; $i < 5; $i++) {
            $this->post("/vitrine/{$b->slug}/commande", $this->humain() + $donnees)->assertRedirect();
        }
        $this->post("/vitrine/{$b->slug}/commande", $this->humain() + $donnees)->assertStatus(429);
    }

    public function test_reglages_de_la_vitrine_dans_les_parametres(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1F6F54',
            'vitrine_active' => '1', 'vitrine_stock_visible' => '0', 'vitrine_message' => 'Bienvenue'])->assertSessionHas('succes');
        $b->refresh();
        $this->assertTrue($b->vitrine_active);
        $this->assertFalse($b->vitrine_stock_visible);
        $this->assertSame('Bienvenue', $b->vitrine_message);
        $this->get('/parametres')->assertSee(route('vitrine.index', $b->slug));
    }

    public function test_la_tva_est_expliquee_dans_les_parametres_et_la_fiche_produit(): void
    {
        // Boutique non assujettie : prix de vente sans mention « HT », pas de prix TTC
        [, $sansTva] = $this->creerBoutique('Sans TVA', 'sans@test.gn');
        $this->actingAs($sansTva)->get('/produits/nouveau')->assertOk()->assertSee('Prix de vente (GNF)')->assertDontSee('id="prixTtc"', false);

        // Boutique qui facture la TVA à 18 %
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $this->actingAs($admin)->get('/parametres')->assertOk()->assertSee('Ce réglage ne concerne que')->assertSee('hors taxe')->assertSee('11 800 GNF');
        $this->get('/produits/nouveau')->assertSee('Prix de vente HT (GNF)')->assertSee('id="prixTtc"', false)->assertSee('data-taux-boutique="18"', false);
    }

    public function test_la_vitrine_et_les_etiquettes_montrent_le_prix_paye_par_le_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['vitrine_active' => true]);
        $p = $this->produit($b, ['designation' => 'Ciment', 'prix_achat' => 50_000, 'prix_vente' => 100_000, 'en_vitrine' => true], 10);
        $format = array_key_first(\App\Http\Controllers\EtiquetteController::FORMATS);
        $etiquette = fn () => $this->actingAs($admin->fresh())->post('/produits/etiquettes', ['quantites' => [$p->id => 1], 'format' => $format, 'prix' => 1]);

        // Sans TVA : le prix saisi
        $this->get('/vitrine/'.$b->slug)->assertSee('100000');
        $etiquette()->assertSee('100 000 GNF');

        // TVA 18 % en prix hors taxe : le client paie 118 000, c'est ce qu'il doit voir
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $this->app->forgetInstance(\App\Support\BoutiqueCourante::class);
        $this->get('/vitrine/'.$b->slug)->assertSee('118000')->assertDontSee('"prix":100000', false);
        $etiquette()->assertSee('118 000 GNF')->assertDontSee('100 000 GNF');

        // Prix TTC : le prix saisi est déjà celui payé
        $b->update(['prix_ttc' => true]);
        $this->app->forgetInstance(\App\Support\BoutiqueCourante::class);
        $this->get('/vitrine/'.$b->slug)->assertSee('100000')->assertDontSee('118000');
        $etiquette()->assertSee('100 000 GNF');
    }
}
