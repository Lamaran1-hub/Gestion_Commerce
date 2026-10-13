<?php

namespace Tests\Feature;

use App\Models\JournalActivite;
use App\Models\Paiement;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\Registre;
use Tests\TestCase;

/** Paiements électroniques : numéro de transaction unique, pointage avec les SMS ou le relevé, litiges. */
class PointagePaiementsTest extends TestCase
{
    private function vente(int $produitId, array $extra = []): array
    {
        return $extra + ['lignes' => [['produit_id' => $produitId, 'quantite' => 1]], 'mode' => 'orange_money'];
    }

    public function test_un_meme_numero_de_transaction_ne_regle_pas_deux_ventes(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 20);   // 300 000
        $this->actingAs($admin);

        $this->post('/ventes', $this->vente($p->id, ['reference' => 'OM 123-456']))->assertSessionHas('succes');
        // Même numéro, écrit autrement : refusé
        $this->post('/ventes', $this->vente($p->id, ['reference' => 'om123456']))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'a déjà été encaissé') && str_contains($m, 'V-'));
        $this->assertSame(1, $this->dans($b, fn () => Vente::count()));
        // Autre moyen de paiement, ou espèces : pas de contrôle
        $this->post('/ventes', $this->vente($p->id, ['mode' => 'mtn_momo', 'reference' => 'OM123456']))->assertSessionHas('succes');
        $this->post('/ventes', $this->vente($p->id, ['mode' => 'especes', 'reference' => 'OM123456']))->assertSessionHas('succes');
        // Paiement mixte : le même numéro deux fois dans la vente
        $this->post('/ventes', $this->vente($p->id, ['mode' => 'orange_money', 'reference' => 'TX9999',
            'paiements_autres' => [['mode' => 'orange_money', 'montant' => '100 000', 'reference' => 'tx 9999']]]))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'saisi deux fois'));
        // Paiement d'un crédit avec un numéro déjà utilisé
        $client = $this->dans($b, fn () => \App\Models\Client::create(['nom' => 'Kaba', 'telephone' => '620000001']));
        $this->post('/ventes', $this->vente($p->id, ['mode' => 'especes', 'montant_recu' => '0', 'client_id' => $client->id]))->assertSessionHas('succes');
        $credit = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->post("/ventes/{$credit->id}/paiements", ['montant' => '100 000', 'mode' => 'orange_money', 'reference' => 'OM-123456'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'a déjà été encaissé'));
        $this->post("/ventes/{$credit->id}/paiements", ['montant' => '100 000', 'mode' => 'orange_money', 'reference' => 'OM777'])->assertSessionHas('succes');
        $this->assertTrue(app(Registre::class)->verifier($b->fresh())['intact']);
    }

    public function test_pointage_litige_et_rappel(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 10)->setTime(10, 0));
        [$b, $admin] = $this->creerBoutique();
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $p = $this->produit($b, [], 20);
        $this->actingAs($vendeur);
        $this->post('/ventes', $this->vente($p->id, ['reference' => 'OM111111']))->assertSessionHas('succes');
        $this->post('/ventes', $this->vente($p->id, ['mode' => 'carte']))->assertSessionHas('succes');
        $this->post('/ventes', $this->vente($p->id, ['mode' => 'especes']))->assertSessionHas('succes');
        $this->get('/pointage-paiements')->assertForbidden();
        $this->get('/tableau-de-bord');

        [$om, $carte] = $this->dans($b, fn () => [Paiement::where('mode', 'orange_money')->sole(), Paiement::where('mode', 'carte')->sole()]);
        $this->flushSession();
        $this->actingAs($admin);
        // Le jour même : rien au tableau de bord ; la page liste les 2 paiements électroniques (pas les espèces)
        $this->get('/tableau-de-bord')->assertDontSee('id="rappelPointage"', false);
        $this->get('/pointage-paiements')->assertOk()->assertSee('id="resumePointage"', false)->assertSee('OM111111')->assertSee('Sans n°')
            ->assertSee('Orange Money')->assertSee('Carte bancaire')->assertDontSee('Espèces</div>', false);

        // 3 jours plus tard, le rappel apparaît
        $this->travel(3)->days();
        $this->flushSession();
        $this->actingAs($admin->fresh());
        $this->get('/tableau-de-bord')->assertSee('id="rappelPointage"', false)->assertSee('2 paiement(s) (600 000 GNF) non pointé(s)');

        // Orange Money retrouvé, carte introuvable
        $this->post('/pointage-paiements', ['paiements' => [$om->id]])->assertSessionHas('succes', fn ($m) => str_contains($m, '1 paiement(s) pointé(s)'));
        $this->post("/pointage-paiements/{$carte->id}/litige", ['note' => 'Absent des SMS / du relevé'])->assertSessionHasNoErrors()->assertSessionMissing('erreur')->assertRedirect()->assertSessionHas('succes');
        $this->assertNotNull($om->fresh()->pointe_le);
        $this->assertSame($admin->id, $om->fresh()->pointe_par);
        $this->assertSame('Absent des SMS / du relevé', $carte->fresh()->litige);
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', 'Paiement introuvable : Carte bancaire de 300 000 GNF sur V-%Absent des SMS / du relevé')->exists()));
        $this->get('/tableau-de-bord')->assertSee('1 paiement(s) introuvable(s)')->assertDontSee('non pointé(s)');
        $this->get('/pointage-paiements?etat=litiges&du=2026-11-01')->assertSee('Litige : Absent des SMS')->assertSee('Retrouvé');
        $this->get('/pointage-paiements?etat=pointes&du=2026-11-01')->assertSee('Pointé le 13/11 par Admin Boutique Test');

        // Retrouvé finalement : de nouveau à pointer ; un paiement en espèces ne se pointe pas
        $this->post("/pointage-paiements/{$carte->id}/annuler")->assertSessionHas('succes');
        $this->assertNull($carte->fresh()->litige);
        $especes = $this->dans($b, fn () => Paiement::where('mode', 'especes')->sole());
        $this->post("/pointage-paiements/{$especes->id}/litige", ['note' => 'Montant différent'])->assertSessionHas('erreur');
        $this->post('/pointage-paiements', ['paiements' => [$especes->id]])->assertSessionHas('succes', fn ($m) => str_contains($m, 'déjà pointés'));
        $this->assertNull($especes->fresh()->pointe_le);
    }
}
