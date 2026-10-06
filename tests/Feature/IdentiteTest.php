<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Depense;
use App\Models\Paiement;
use App\Models\Vente;
use App\Services\VenteService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/** Identité visuelle (logo, couleurs) et choix « Autre » avec précision. */
class IdentiteTest extends TestCase
{
    public function test_la_boutique_peut_avoir_jusqu_a_trois_couleurs(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $base = ['nom' => $b->nom, 'tva_taux' => 18];

        $this->actingAs($admin)->put('/parametres', $base + ['couleur' => '#8A3B12', 'couleur_2' => '#123456', 'couleur_3' => '#F2B233'])
            ->assertSessionHas('succes');
        $this->assertSame(['#8A3B12', '#123456', '#F2B233'], $b->fresh()->couleurs());
        $this->get('/tableau-de-bord')->assertSee('--marque: #8A3B12', false)->assertSee('--accent: #F2B233', false);

        // La couleur 2 retirée : la 3 prend sa place
        $this->put('/parametres', $base + ['couleur' => '#8A3B12', 'couleur_3' => '#F2B233']);
        $this->assertSame(['#8A3B12', '#F2B233'], $b->fresh()->couleurs());

        $this->put('/parametres', $base + ['couleur' => '#8A3B12', 'couleur_2' => 'rouge'])->assertSessionHasErrors('couleur_2');
    }

    public function test_le_fond_sombre_derive_reste_lisible(): void
    {
        foreach (['#F2B233', '#FFFFFF', '#1F6F54', '#00AAFF'] as $couleur) {
            $sombre = \App\Models\Boutique::versionSombre($couleur);
            $this->assertSame('#FFFFFF', \App\Models\Boutique::texteSur($sombre), "texte blanc lisible sur {$sombre}");
        }
        $this->assertSame('#1C2622', \App\Models\Boutique::texteSur('#F2B233'));
    }

    public function test_le_logo_apparait_a_la_connexion_et_dans_l_onglet(): void
    {
        Storage::fake('public');
        [$b, $admin] = $this->creerBoutique('Quincaillerie Camara', 'camara@test.gn');
        $b->update(['logo' => UploadedFile::fake()->image('logo.png')->store('boutiques/'.$b->id, 'public')]);

        // Une seule boutique installée : son logo s'affiche dès la première visite
        $this->get('/connexion')->assertOk()->assertSee('ecranAccueil', false)->assertSee($b->logoUrl(), false);
        $this->creerBoutique('Autre boutique', 'autre@test.gn');
        $this->get('/connexion')->assertSee(config('gestion.logo'), false);

        // Après une connexion, l'appareil retient la boutique
        $this->post('/connexion', ['email' => 'camara@test.gn', 'password' => 'secret123'])->assertCookie('gn_boutique', $b->slug);
        $this->get('/tableau-de-bord')->assertSee('<link rel="icon" href="'.$b->logoUrl().'"', false);
        $this->post('/deconnexion')->assertRedirect('/connexion')->assertSessionHas('afficher_logo');

        $this->withCookie('gn_boutique', $b->slug)->get('/connexion')
            ->assertSee('Quincaillerie Camara')->assertSee($b->logoUrl(), false);

        // Pas d'écran d'accueil au retour d'une erreur de saisie
        $this->from('/connexion')->post('/connexion', ['email' => 'camara@test.gn', 'password' => 'faux'])->assertRedirect('/connexion');
        $this->get('/connexion')->assertDontSee('id="ecranAccueil"', false);
    }

    public function test_les_documents_exportes_portent_le_logo_et_les_coordonnees(): void
    {
        Storage::fake('public');
        [$b, $admin] = $this->creerBoutique('Quincaillerie Camara');
        $b->update(['logo' => UploadedFile::fake()->image('logo.png', 200, 200)->store('boutiques/'.$b->id, 'public'),
            'telephone' => '+224 620 00 00 00', 'nif' => 'NIF-42', 'couleur_2' => '#123456']);
        $this->produit($b);

        $reponse = $this->actingAs($admin)->get('/produits/export/excel')->assertOk()->assertDownload();
        $classeur = IOFactory::load($reponse->baseResponse->getFile()->getPathname());
        $feuille = $classeur->getActiveSheet();
        $this->assertSame('Quincaillerie Camara', $feuille->getCell('A4')->getValue());
        $this->assertStringContainsString('NIF : NIF-42', $feuille->getCell('A6')->getValue());
        $this->assertSame('Désignation', $feuille->getCell('A9')->getValue());
        $this->assertSame('Riz 25 kg', $feuille->getCell('A10')->getValue());
        $this->assertCount(1, $feuille->getDrawingCollection(), 'le logo est inséré');

        $this->get('/produits/export/pdf')->assertOk()->assertDownload();
        $this->get('/ventes/export?format=pdf')->assertOk()->assertDownload();
    }

    public function test_choix_autre_avec_ou_sans_precision(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin);
        $depense = ['motif' => 'Achat', 'montant' => '10 000', 'date_depense' => now()->toDateString()];

        $this->post('/depenses', $depense + ['categorie' => 'Autre', 'categorie_autre' => 'Gardiennage'])->assertSessionHas('succes');
        $this->post('/depenses', $depense + ['categorie' => 'Autre', 'categorie_autre' => ''])->assertSessionHas('succes');
        $this->post('/depenses', $depense + ['categorie' => 'Loyer', 'categorie_autre' => 'ignoré'])->assertSessionHas('succes');
        $this->assertSame(['Gardiennage', 'Autre', 'Loyer'], $this->dans($b, fn () => Depense::orderBy('id')->pluck('categorie')->all()));

        $p = $this->produit($b);
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]), $admin);
        $this->post("/ventes/{$v->id}/annuler", ['motif' => 'Autre', 'motif_autre' => 'Prix mal affiché'])->assertSessionHas('succes');
        $this->assertSame('Prix mal affiché', $v->fresh()->motif_annulation);

        $client = $this->dans($b, fn () => Client::create(['nom' => 'Diallo']));
        $v2 = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $client->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]), $admin);
        $this->post("/ventes/{$v2->id}/paiements", ['montant' => '300 000', 'mode' => 'autre', 'mode_autre' => 'Wave', 'reference' => 'TX1'])->assertSessionHasNoErrors()->assertSessionHas('succes');
        $this->assertSame('Wave — TX1', $this->dans($b, fn () => Paiement::where('vente_id', $v2->id)->latest('id')->value('reference')));
    }

    public function test_ajout_rapide_d_une_categorie_et_d_un_fournisseur(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->postJson('/categories', ['nom' => 'Boissons'])->assertOk()->assertJsonPath('nom', 'Boissons');
        $this->postJson('/categories', ['nom' => 'Boissons'])->assertStatus(422)->assertJsonValidationErrors('nom');
        $this->postJson('/fournisseurs', ['nom' => 'Sodeci'])->assertOk()->assertJsonStructure(['id', 'nom']);
    }

    public function test_les_raccourcis_menent_aux_sections_liees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->get('/produits')->assertSee('Accès rapide')
            ->assertSee(route('categories.index'), false)->assertSee(route('stock.inventaire'), false);
    }

    public function test_le_logo_personnalise_n_apparait_que_si_la_licence_est_payee(): void
    {
        Storage::fake('public');
        [$b, $admin] = $this->creerBoutique();
        $b->update(['logo' => UploadedFile::fake()->image('logo.png')->store('boutiques/'.$b->id, 'public'), 'statut' => 'essai']);
        $url = $b->logoTeleverseUrl();

        $this->actingAs($admin)->get('/tableau-de-bord')->assertDontSee($url, false)->assertSee(config('gestion.logo'), false);
        $this->get('/parametres')->assertSee($url, false)->assertSee('dès que votre licence sera payée');

        $b->update(['statut' => 'actif']); // paiement reçu : l'éditeur active la licence
        $this->actingAs($admin->fresh());
        $this->get('/tableau-de-bord')->assertSee('<link rel="icon" href="'.$url.'"', false);
        $this->assertStringStartsWith(url('/storage/'), $url, "l'adresse suit l'hôte utilisé, pas APP_URL");
    }

    public function test_on_navigue_librement_puis_deconnexion_apres_30_minutes_d_inactivite(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->post('/connexion', ['email' => 'admin@test.gn', 'password' => 'secret123'])->assertRedirect();
        foreach (['/tableau-de-bord', '/produits', '/ventes', '/depenses'] as $page) {
            $this->get($page)->assertOk();
        }

        $this->travel(29)->minutes();
        $this->get('/clients')->assertOk(); // l'activité repousse l'échéance

        $this->travel(31)->minutes();
        $this->get('/produits')->assertRedirect('/connexion')->assertSessionHas('afficher_logo');
        $this->assertGuest();
        $this->get('/connexion')->assertSee('minutes d&#039;inactivité', false);
    }
}
