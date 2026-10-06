<?php

namespace Tests\Feature;

use App\Models\Pointage;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\CaisseService;
use App\Services\Registre;
use App\Services\VenteService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Inaltérabilité des encaissements (registre chaîné), analyse des ventes, gestion d'équipe (pointage). */
class ConformiteEtEquipeTest extends TestCase
{
    private function ventes($b, $admin, $p): Vente
    {
        $vs = app(VenteService::class);
        $v = $this->dans($b, fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 2]], 'mode' => 'especes']), $admin);
        $this->dans($b, fn () => $vs->creer(['lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'orange_money']), $admin);

        return $v;
    }

    public function test_registre_intact_puis_fraude_detectee(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $v = $this->ventes($b, $admin, $p);
        // Retour, annulation et clôture sont aussi signés
        $this->actingAs($admin)->post("/ventes/{$v->id}/retours", ['quantites' => [$v->lignes->first()->id => 1], 'motif' => 'Autre', 'mode_remboursement' => 'especes']);
        $v2 = Vente::withoutGlobalScopes()->latest('id')->first();
        $this->post("/ventes/{$v2->id}/annuler", ['motif' => 'Erreur']);
        $this->dans($b, fn () => app(CaisseService::class)->cloturer($admin, 300_000, null, null, $admin), $admin);

        $r = $this->dans($b, fn () => app(Registre::class)->verifier($b));
        $this->assertTrue($r['intact'], implode(' | ', $r['anomalies']));
        $this->assertSame(['vente', 'paiement', 'vente', 'paiement', 'retour', 'paiement', 'annulation', 'cloture'],
            DB::table('registre_caisse')->orderBy('sequence')->pluck('type')->all());
        $this->assertNotNull($v->fresh()->empreinte);
        $this->get("/ventes/{$v->id}/recu?apercu=1")->assertSee('Empreinte');
        $this->get('/controle-integrite')->assertOk()->assertSee('Registre intact');

        // Fraude : quelqu'un baisse le montant d'une vente et d'un paiement directement dans la base
        DB::table('ventes')->where('id', $v->id)->update(['total_ttc' => 100]);
        DB::table('paiements')->where('vente_id', $v2->id)->update(['montant' => 1]);
        $r = $this->dans($b, fn () => app(Registre::class)->verifier($b));
        $this->assertFalse($r['intact']);
        $texte = implode(' | ', $r['anomalies']);
        $this->assertStringContainsString("{$v->numero} : montant modifié", $texte);
        $this->assertStringContainsString('Paiement sur la vente '.$v2->numero.' modifié', $texte);
        $this->get('/controle-integrite')->assertSee('anomalie(s) détectée(s)');

        // Falsifier le registre lui-même casse la chaîne ; supprimer une ligne aussi
        DB::table('registre_caisse')->where('sequence', 3)->update(['donnees' => '{"numero":"X"}']);
        DB::table('registre_caisse')->where('sequence', 5)->delete();
        $texte = implode(' | ', $this->dans($b, fn () => app(Registre::class)->verifier($b))['anomalies']);
        $this->assertStringContainsString("Registre modifié à l'opération n° 3", $texte);
        $this->assertStringContainsString('n° 5 à 5 supprimé', $texte);
    }

    public function test_analyse_des_ventes(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);
        $this->ventes($b, $admin, $p); // 600 000 + 300 000
        $this->actingAs($admin)->get('/analyse-des-ventes')->assertOk()
            ->assertSee('900 000 GNF')->assertSee('450 000 GNF') // panier moyen
            ->assertSee('Heures de pointe')->assertSee('Les plus rentables')->assertSee('Orange Money');

        // Sans rapports avancés : indicateurs de base seulement
        [$b2, $admin2] = $this->creerBoutique('Petite', 'petite@test.gn', false);
        $this->actingAs($admin2)->get('/analyse-des-ventes')->assertOk()->assertSee('Panier moyen')->assertDontSee('Les plus rentables')->assertSee('rapports avancés');
    }

    public function test_pointage_et_synthese_de_l_equipe(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'role_id' => Role::where('nom', 'Vendeur')->first()->id,
            'prenom' => 'Awa', 'nom' => 'Camara', 'email' => 'awa@test.gn', 'password' => 'secret123', 'actif' => true]));

        $this->travelTo(now()->setTime(8, 0));
        $this->actingAs($vendeur)->withSession(['derniere_activite' => now()->timestamp])->post('/pointage')->assertSessionHas('succes', fn ($m) => str_contains($m, 'Arrivée pointée'));
        $this->get('/caisse')->assertSee('Pointer mon départ');
        $this->travelTo(now()->setTime(17, 30));
        $this->withSession(['derniere_activite' => now()->timestamp])->post('/pointage')->assertSessionHas('succes', fn ($m) => str_contains($m, '9 h 30'));
        // Le vendeur ne voit que ses pointages et ne peut pas corriger
        $this->withSession(['derniere_activite' => now()->timestamp])->get('/equipe')->assertOk()->assertSee('Mes pointages');
        $pt = Pointage::withoutGlobalScopes()->first();
        $this->put("/equipe/{$pt->id}", ['arrivee' => now()->toDateTimeString(), 'note' => 'x'])->assertForbidden();

        // Oubli de départ la veille : bloqué, le responsable corrige avec un motif
        $this->travelTo(now()->addDay()->setTime(8, 0));
        Pointage::withoutGlobalScopes()->create(['boutique_id' => $b->id, 'user_id' => $vendeur->id, 'arrivee' => now()->subDay()->setTime(18, 0)]);
        $this->withSession(['derniere_activite' => now()->timestamp])->post('/pointage')->assertSessionHas('erreur', fn ($m) => str_contains($m, "n'a pas été pointé"));
        $oubli = Pointage::withoutGlobalScopes()->whereNull('depart')->first();
        $this->actingAs($admin)->withSession(['derniere_activite' => now()->timestamp])
            ->put("/equipe/{$oubli->id}", ['arrivee' => $oubli->arrivee->format('Y-m-d H:i'), 'depart' => $oubli->arrivee->copy()->setTime(20, 0)->format('Y-m-d H:i'), 'note' => 'Oubli signalé'])
            ->assertSessionHas('succes');
        $this->get('/equipe?du='.now()->subDays(2)->toDateString())->assertOk()->assertSee('Présents maintenant')->assertSee('Awa Camara')->assertSee('11 h 30');
    }
}
