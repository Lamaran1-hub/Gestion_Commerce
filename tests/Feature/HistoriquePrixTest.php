<?php

namespace Tests\Feature;

use App\Models\Fournisseur;
use App\Models\HistoriquePrix;
use App\Models\JournalActivite;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/** Chaque changement de prix est tracé : fiche produit, réception, passage TVA ; visible sur la fiche et dans un rapport. */
class HistoriquePrixTest extends TestCase
{
    public function test_changements_traces_avec_leur_origine(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 10);   // achat 250 000, vente 300 000
        $base = ['designation' => $p->designation, 'unite' => 'sac', 'prix_achat' => 250_000, 'prix_vente' => 300_000];

        // Fiche produit : baisse du prix de vente
        $this->actingAs($admin)->put("/produits/{$p->id}", ['prix_vente' => 280_000] + $base)->assertSessionHasNoErrors();
        // Modification sans changement de prix : rien de noté
        $this->put("/produits/{$p->id}", ['prix_vente' => 280_000, 'seuil_alerte' => 5] + $base)->assertSessionHasNoErrors();

        // Réception à un nouveau prix d'achat
        $f = $this->dans($b, fn () => Fournisseur::create(['nom' => 'Ciments de Guinée']));
        $this->post('/approvisionnements', ['fournisseur_id' => $f->id, 'date_appro' => now()->toDateString(), 'reglement' => 'credit',
            'lignes' => [['produit_id' => $p->id, 'quantite' => 5, 'prix_achat_unitaire' => '260 000']]])->assertSessionHasNoErrors();

        $historique = $this->dans($b, fn () => HistoriquePrix::orderBy('id')->get());
        $this->assertSame([['prix_vente', 300_000, 280_000, 'Fiche produit'], ['prix_achat', 250_000, 260_000]],
            [[$historique[0]->champ, $historique[0]->ancien, $historique[0]->nouveau, $historique[0]->origine],
                [$historique[1]->champ, $historique[1]->ancien, $historique[1]->nouveau]]);
        $this->assertStringStartsWith('Réception AP-', $historique[1]->origine);
        $this->assertCount(2, $historique);
        $this->assertSame($admin->id, $historique[0]->user_id);
        $this->assertSame(-6.7, $historique[0]->variation());
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', 'Prix de vente de Riz 25 kg : 300 000 GNF → 280 000 GNF%')->exists()));

        // Fiche produit et rapport
        $this->get("/produits/{$p->id}")->assertOk()->assertSee('Historique des prix')->assertSee('-6,7 %')->assertSee('Réception AP-');
        $this->get('/rapports/prix/ecran')->assertOk()->assertSee('Changements de prix')->assertSee('280 000')->assertSee('Fiche produit');

        // Un vendeur (sans le droit « prix d'achat ») ne voit pas les changements de prix d'achat
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $this->actingAs($vendeur)->get("/produits/{$p->id}")->assertOk()->assertSee('Prix de vente')->assertDontSee('260 000');
    }

    public function test_passage_ttc_trace_sans_inonder_le_journal(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        foreach (range(1, 5) as $i) {
            $this->produit($b->fresh(), ['designation' => "Produit {$i}", 'prix_vente' => 100_000], 0);
        }
        $this->actingAs($admin)->put('/parametres', ['nom' => $b->nom, 'tva_taux' => 18, 'couleur' => '#1F6F54', 'tva_active' => 1, 'prix_ttc' => 1, 'ajuster_prix' => 1])
            ->assertSessionHas('succes');

        $this->assertSame(5, $this->dans($b, fn () => HistoriquePrix::where('origine', 'Passage aux prix TTC')->where('nouveau', 118_000)->count()));
        // Un seul message de synthèse au journal, pas un par produit
        $this->assertSame(0, $this->dans($b, fn () => JournalActivite::where('description', 'like', 'Prix de vente de Produit%')->count()));
        $this->assertSame(1, $this->dans($b, fn () => JournalActivite::where('description', 'like', 'Passage aux prix TVA comprise%')->count()));
    }
}
