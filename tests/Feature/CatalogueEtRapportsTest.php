<?php

namespace Tests\Feature;

use App\Models\Categorie;
use App\Models\Produit;
use App\Services\StockService;
use App\Services\VenteService;
use App\Support\CodeBarre;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** Import Excel du catalogue, étiquettes code-barres, nouveaux rapports (vendeurs, catégories, pertes, TVA). */
class CatalogueEtRapportsTest extends TestCase
{
    private function fichier(array $lignes): UploadedFile
    {
        $classeur = new Spreadsheet;
        $classeur->getActiveSheet()->fromArray($lignes);
        $chemin = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($classeur))->save($chemin);

        return new UploadedFile($chemin, 'catalogue.xlsx', null, null, true);
    }

    public function test_code_128_et_ean_interne(): void
    {
        foreach (CodeBarre::MOTIFS as $i => $m) {
            $this->assertSame($i === 106 ? 13 : 11, array_sum(str_split($m)), "motif $i");
        }
        // Jeu C : départ 105 + 1×12 + 2×34 = 185 ≡ 82 (mod 103)
        $this->assertSame([105, 12, 34, 82, 106], CodeBarre::symboles('1234'));
        $this->assertSame(104, CodeBarre::symboles('ABC')[0]);
        $this->assertSame('2000000000015', CodeBarre::ean13Interne(1));
        $this->assertStringStartsWith('<svg', CodeBarre::svg('2000000000015'));
    }

    public function test_import_apercu_puis_confirmation(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $riz = $this->produit($b, ['code_barre' => '111'], 12);

        $f = $this->fichier([
            ['Boutique Test'],                                                     // ligne de titre ignorée
            ['Désignation', 'Code-barres', 'Catégorie', 'Unité', "Prix d'achat", 'Prix de vente', 'Stock'],
            ['Riz renommé', '111', 'Alimentation', 'sac', 260000, '320 000', 99],    // mise à jour (stock inchangé)
            ['Savon', '', 'Hygiène', 'pièce', 2000, 3000, 50],                       // création
            ['Lait', '', '', 'boîte', 12000, 10000, 5],                              // vente à perte : refusé
            ['', '', '', '', '', 5000, ''],                                          // sans désignation
        ]);
        $this->actingAs($admin)->post('/produits/import/apercu', ['fichier' => $f])->assertOk()
            ->assertSee('1 à créer')->assertSee('1 à mettre à jour')->assertSee('2 en erreur')->assertSee('inférieur au prix d');
        $this->assertSame(1, $this->dans($b, fn () => Produit::count()), 'rien n\'est enregistré avant confirmation');

        $this->post('/produits/import')->assertRedirect('/produits')->assertSessionHas('succes', fn ($m) => str_contains($m, '1 produit(s) créé(s), 1 mis à jour'));
        $riz = $riz->fresh();
        $this->assertSame('Riz renommé', $riz->designation);
        $this->assertSame(320_000, $riz->prix_vente);
        $this->assertEquals(12, $riz->stock);
        $savon = $this->dans($b, fn () => Produit::where('designation', 'Savon')->first());
        $this->assertEquals(50, $savon->stock);
        $this->assertSame('Hygiène', $this->dans($b, fn () => Categorie::find($savon->categorie_id)->nom));
    }

    public function test_modele_et_etiquettes(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $sans = $this->produit($b, ['designation' => 'Savon', 'prix_achat' => 2000, 'prix_vente' => 3000]);
        $this->actingAs($admin)->get('/produits/import/modele')->assertOk()->assertDownload('modele-import-produits.xlsx');

        $this->get('/produits/etiquettes')->assertOk()->assertSee('Savon');
        $this->post('/produits/etiquettes', ['quantites' => [$sans->id => 3], 'format' => '24', 'prix' => 1])
            ->assertOk()->assertSee('3 étiquette(s)')->assertSee('<svg', false)->assertSee('3 000 GNF');
        $this->assertSame(CodeBarre::ean13Interne($sans->id), $sans->fresh()->code_barre, 'code interne attribué et gardé');
    }

    public function test_rapports_vendeurs_pertes_et_resultat(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20); // achat 250 000, vente 300 000
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        // Inventaire : 2 sacs manquants → perte de 500 000
        $this->dans($b, fn () => app(StockService::class)->ajuster($p->fresh(), 16, 'Inventaire'), $admin);

        $this->actingAs($admin)->get('/rapports/vendeurs/ecran')->assertOk()->assertSee('Admin')->assertSee('600 000 GNF')->assertSee('100 000 GNF');
        $this->get('/rapports/pertes/ecran')->assertOk()->assertSee('-500 000 GNF');
        // Marge 100 000 − pertes 500 000 = −400 000
        $this->get('/rapports/resultat/ecran')->assertOk()->assertSee('Pertes et écarts de stock')->assertSee('-400 000 GNF');
        $this->get('/rapports/categories/ecran')->assertOk()->assertSee('Sans catégorie');
        $this->get('/rapports/tva/ecran')->assertOk();
        $this->get('/rapports/vendeurs/excel')->assertOk();
    }
}
