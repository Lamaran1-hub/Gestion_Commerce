<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\CommandeFournisseur;
use App\Models\Devis;
use App\Models\NumeroSerie;
use App\Models\Vente;
use App\Services\ResumeQuotidien;
use App\Services\VenteService;
use Tests\TestCase;

/** Les modules se croisent : retour d'appareils suivis, résumé du jour, tableau de bord. */
class CoherenceModulesTest extends TestCase
{
    private function venteDeuxTelephones($b, $admin): Vente
    {
        $p = $this->produit($b, ['designation' => 'Téléphone Itel A70', 'garantie_mois' => 6, 'suivi_serie' => true], 10);
        $c = $this->dans($b, fn () => Client::create(['nom' => 'Diallo', 'prenom' => 'Oumar', 'telephone' => '622111222']));
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin)->load('lignes');
        $this->actingAs($admin)->post("/ventes/{$v->id}/numeros-serie", ['series' => [$v->lignes->first()->id => ['IMEI-AAA111', 'IMEI-BBB222']]])->assertSessionHas('succes');

        return $v;
    }

    public function test_retour_d_un_appareil_suivi_designe_son_numero_et_le_rend_revendable(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $v = $this->venteDeuxTelephones($b, $admin);
        $l = $v->lignes->first();
        [$a, $bb] = $this->dans($b, fn () => NumeroSerie::orderBy('id')->get()->all());
        $this->get("/ventes/{$v->id}")->assertSee('series_retour['.$l->id.'][]', false);

        // Sans désigner le numéro : refusé ; deux numéros pour un appareil : refusé
        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$l->id => 1], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'cochez le numéro de série'));
        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$l->id => 1], 'series_retour' => [$l->id => [$a->id, $bb->id]], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('erreur');

        $this->post("/ventes/{$v->id}/retours", ['quantites' => [$l->id => 1], 'series_retour' => [$l->id => [$bb->id]], 'motif' => 'Produit défectueux', 'mode_remboursement' => 'especes'])
            ->assertSessionHas('succes');
        $this->assertNotNull($bb->fresh()->retour_id);
        $this->assertNull($a->fresh()->retour_id);
        $this->assertSame(['IMEIAAA111'], $this->dans($b, fn () => $l->fresh()->numerosSerie->pluck('numero')->all()), 'seul l\'appareil gardé reste chez le client');
        $this->get("/ventes/{$v->id}")->assertSee('Rapportés')->assertSee('IMEIBBB222');
        $this->get('/garanties?q=BBB222')->assertSee('Rapporté');
        $this->get('/garanties?q=AAA111')->assertSee('Garanti jusqu');

        // L'appareil rapporté peut être revendu, celui gardé par le client non
        $p2 = $this->dans($b, fn () => \App\Models\Produit::where('designation', 'Téléphone Itel A70')->first());
        $v2 = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p2->id, 'quantite' => 1]], 'mode' => 'especes']), $admin)->load('lignes');
        $this->post("/ventes/{$v2->id}/numeros-serie", ['series' => [$v2->lignes->first()->id => ['IMEI-AAA111']]])->assertSessionHas('erreur');
        $this->post("/ventes/{$v2->id}/numeros-serie", ['series' => [$v2->lignes->first()->id => ['IMEI-BBB222']]])->assertSessionHas('succes');

        // Réenregistrer les numéros de la 1re vente ne touche pas à l'historique du retour
        $this->post("/ventes/{$v->id}/numeros-serie", ['series' => [$l->id => ['IMEI-AAA111']]])->assertSessionHas('succes');
        $this->assertNotNull($bb->fresh()?->retour_id);
    }

    public function test_resume_du_jour_et_tableau_de_bord_signalent_les_nouveaux_retards(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, ['suivi_serie' => true], 10);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        $v->update(['livraison' => 'a_livrer', 'livraison_adresse' => 'Kaloum', 'livraison_prevue_le' => now()->subDays(2)]);
        $this->dans($b, fn () => CommandeFournisseur::create(['numero' => 'CF-T1', 'date_commande' => now()->subDays(10), 'livraison_prevue_le' => now()->subDay(), 'statut' => 'envoyee']));
        $this->actingAs($admin)->post('/devis', ['client_nom' => 'Awa', 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);
        $d = $this->dans($b, fn () => Devis::first());
        $this->post("/devis/{$d->id}/acomptes", ['montant' => '50 000', 'mode' => 'orange_money'])->assertSessionHas('succes');
        $d->update(['valable_jusqu_au' => now()->subDay()]);

        $r = app(ResumeQuotidien::class)->calculer($b->fresh(), now());
        $this->assertSame(1, $r['livraisons_retard']);
        $this->assertSame(1, $r['commandes_fournisseur_retard']);
        $this->assertSame(50_000, $r['acomptes_expires']);
        $this->assertSame(1, $r['series_manquantes']);

        $this->get('/tableau-de-bord')->assertSeeText("50 000 GNF d'acomptes clients", false)->assertSeeText('1 commande(s) fournisseur')->assertSeeText('dont 1 en retard');
    }
}
