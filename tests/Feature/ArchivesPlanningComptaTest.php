<?php

namespace Tests\Feature;

use App\Models\Depense;
use App\Models\Planning;
use App\Models\Pointage;
use App\Models\Role;
use App\Models\User;
use App\Services\ArchivesFiscales;
use App\Services\VenteService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Archives fiscales signées, planning des équipes, écritures comptables SYSCOHADA, guide de démarrage. */
class ArchivesPlanningComptaTest extends TestCase
{
    public function test_archive_mensuelle_signee_et_verifiable(): void
    {
        Storage::fake();
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $this->travelTo(now()->subMonthNoOverflow()->startOfMonth()->addDays(3));
        $this->dans($b, fn () => app(VenteService::class)->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $this->travelBack();

        // Archivage automatique du mois précédent (tâche du 1er du mois)
        $this->assertSame(1, app(ArchivesFiscales::class)->archiverMoisPrecedent());
        $a = DB::table('archives_fiscales')->first();
        $this->assertSame(600_000, (int) $a->total_ttc);
        $contenu = json_decode(Storage::get($a->fichier), true);
        $this->assertSame(1, $contenu['totaux']['ventes_validees']);
        $this->assertNotEmpty($contenu['registre']);
        $this->assertTrue(app(ArchivesFiscales::class)->verifier($a));

        $this->actingAs($admin)->get('/controle-integrite')->assertOk()->assertSee('Intacte');
        $this->get("/archives-fiscales/{$a->id}")->assertOk()->assertDownload();
        // Pas deux fois le même mois, pas de mois en cours
        $this->post('/archives-fiscales', ['mois' => Carbon::parse($a->periode_du)->format('Y-m')])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà archivé'));
        $this->post('/archives-fiscales', ['mois' => now()->format('Y-m')])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'terminé'));

        // Fichier modifié après coup : détecté, plus téléchargeable
        Storage::put($a->fichier, str_replace('600000', '100', Storage::get($a->fichier)));
        $this->assertFalse(app(ArchivesFiscales::class)->verifier($a));
        $this->get('/controle-integrite')->assertSee('Modifiée ou absente');
        $this->get("/archives-fiscales/{$a->id}")->assertStatus(409);
    }

    public function test_planning_couverture_retards_et_copie(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'role_id' => Role::where('nom', 'Vendeur')->first()->id,
            'prenom' => 'Awa', 'nom' => 'Camara', 'email' => 'awa@test.gn', 'password' => 'secret123', 'actif' => true]));
        $lundi = now()->startOfWeek(Carbon::MONDAY);
        $jour = $lundi->toDateString();

        $this->actingAs($admin)->post('/planning', ['semaine' => $jour, 'horaires' => [$vendeur->id => [$jour => ['debut' => '08:00', 'fin' => '17:00']]]])
            ->assertSessionHas('succes');
        $this->assertSame(1, $this->dans($b, fn () => Planning::count()));
        // Heure de fin avant le début : refusé
        $this->post('/planning', ['semaine' => $jour, 'horaires' => [$vendeur->id => [$jour => ['debut' => '17:00', 'fin' => '08:00']]]])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'la fin après le début'));
        // Même horaire réenregistré : pas de doublon
        $this->post('/planning', ['semaine' => $jour, 'horaires' => [$vendeur->id => [$jour => ['debut' => '08:30', 'fin' => '17:00']]]]);
        $this->assertSame(1, $this->dans($b, fn () => Planning::count()));

        // Retard de 40 min pointé ce jour-là
        $this->dans($b, fn () => Pointage::create(['user_id' => $vendeur->id, 'arrivee' => $lundi->copy()->setTime(9, 10), 'depart' => $lundi->copy()->setTime(17, 0)]));
        $this->travelTo($lundi->copy()->setTime(18, 0));
        $this->actingAs($admin)->withSession(['derniere_activite' => now()->timestamp])->get('/planning?semaine='.$jour)->assertOk()
            ->assertSee('Retard de 40 min')->assertSee('Couverture et affluence')->assertSee('08:30');
        // L'employé voit son planning, sans pouvoir le modifier
        $this->actingAs($vendeur)->withSession(['derniere_activite' => now()->timestamp])->get('/planning?semaine='.$jour)->assertOk()->assertSee('Mon planning')->assertSee('08:30 – 17:00');
        $this->post('/planning', ['semaine' => $jour, 'horaires' => []])->assertForbidden();

        // Recopie sur la semaine suivante
        $suivante = $lundi->copy()->addWeek()->toDateString();
        $this->actingAs($admin)->withSession(['derniere_activite' => now()->timestamp])->post('/planning/copier?semaine='.$suivante)->assertSessionHas('succes', fn ($m) => str_contains($m, '1 horaire'));
        $this->assertSame(2, $this->dans($b, fn () => Planning::count()));
    }

    public function test_ecritures_comptables_equilibrees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['tva_active' => true, 'tva_taux' => 18]);
        $p = $this->produit($b, [], 20);
        $vs = app(VenteService::class);
        $this->dans($b->fresh(), fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'orange_money']), $admin);
        $this->dans($b->fresh(), fn () => Depense::create(['motif' => 'Loyer', 'categorie' => 'Loyer', 'montant' => 50_000, 'mode' => 'especes', 'date_depense' => now()->toDateString(), 'user_id' => $admin->id]));

        $r = $this->actingAs($admin->fresh())->get('/rapports/comptable/ecran')->assertOk();
        // 300 000 HT + 18 % = 354 000 TTC ; Orange Money → 552 ; loyer → 622 / 571
        $r->assertSee('411')->assertSee('701')->assertSee('4431')->assertSee('552')->assertSee('622')->assertSee('354 000 GNF')->assertSee('54 000 GNF');
        $html = $r->getContent();
        preg_match('#Total</td>.*?(\d[\d\s ]*) GNF.*?(\d[\d\s ]*) GNF#su', $html, $m);
        $this->assertNotEmpty($m, 'ligne de total');
        $this->assertSame(preg_replace('/\D/u', '', $m[1]), preg_replace('/\D/u', '', $m[2]), 'total débit = total crédit');
        $this->get('/rapports/comptable/excel')->assertOk()->assertDownload();
    }

    public function test_guide_de_demarrage(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->get('/tableau-de-bord')->assertSee('Bien démarrer (0/5)');
        $this->produit($b, [], 5);
        $this->get('/tableau-de-bord')->assertSee('Bien démarrer (1/5)');
        $this->post('/demarrage/masquer');
        $this->get('/tableau-de-bord')->assertDontSee('Bien démarrer');
    }
}
