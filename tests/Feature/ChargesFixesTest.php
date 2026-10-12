<?php

namespace Tests\Feature;

use App\Models\ChargeFixe;
use App\Models\Depense;
use App\Models\Role;
use App\Models\User;
use App\Services\CaisseService;
use Tests\TestCase;

/** Charges fixes : rappel mensuel (payée, à payer, en retard), paiement en un clic, jamais deux fois le même mois. */
class ChargesFixesTest extends TestCase
{
    public function test_rappel_et_paiement_d_une_charge_fixe(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(10, 0));
        [$b, $admin] = $this->creerBoutique();

        $this->actingAs($admin)->post('/charges-fixes', ['motif' => 'Loyer du magasin', 'montant' => '1 500 000', 'jour' => 5, 'categorie' => 'Loyer', 'mode' => 'virement'])
            ->assertSessionHas('succes');
        $this->post('/charges-fixes', ['motif' => 'Électricité EDG', 'montant' => '250 000', 'jour' => 15, 'mode' => 'especes']);
        $this->post('/charges-fixes', ['motif' => 'Internet', 'montant' => '300 000', 'jour' => 31, 'mode' => 'virement'])->assertSessionHasErrors('jour');
        [$loyer, $edg] = $this->dans($b, fn () => ChargeFixe::orderBy('id')->get()->all());

        // Le 12 : loyer en retard (dû le 5), électricité à payer bientôt (le 15)
        $this->get('/depenses')->assertOk()->assertSee('Charges fixes de novembre 2026')->assertSee('en retard')->assertSee('à payer bientôt')
            ->assertSee('reste à payer')->assertSee('1 750 000 GNF');
        $this->get('/tableau-de-bord')->assertSee('2 charge(s) fixe(s) à payer')->assertSee('Loyer du magasin (en retard)');

        // Paiement en un clic : une dépense ordinaire rattachée à la charge
        $this->post('/depenses', ['charge_fixe_id' => $loyer->id, 'motif' => 'Loyer du magasin — novembre 2026', 'categorie' => 'Loyer',
            'montant' => '1 500 000', 'mode' => 'virement', 'date_depense' => now()->toDateString()])->assertSessionHas('succes');
        $this->assertSame($loyer->id, $this->dans($b, fn () => Depense::sole())->charge_fixe_id);
        // Pas deux fois le même mois
        $this->post('/depenses', ['charge_fixe_id' => $loyer->id, 'motif' => 'Loyer', 'montant' => '1 500 000', 'mode' => 'virement', 'date_depense' => now()->toDateString()])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà payée pour ce mois'));
        $this->get('/depenses')->assertSee('payée')->assertSee('250 000 GNF');
        $this->get('/tableau-de-bord')->assertSee('1 charge(s) fixe(s) à payer')->assertDontSee('(en retard)');

        // Le mois suivant, le loyer redevient à payer
        $this->travelTo(now()->setDate(2026, 12, 2));
        $this->flushSession();   // un mois plus tard, la session aurait expiré
        $this->actingAs($admin->fresh());
        $this->get('/depenses')->assertSee('Charges fixes de décembre 2026')->assertDontSee('en retard');

        // Arrêter une charge déjà payée la garde (historique) ; une charge jamais payée est supprimée
        $this->delete("/charges-fixes/{$loyer->id}")->assertSessionHas('succes');
        $this->assertFalse($loyer->fresh()->actif);
        $this->delete("/charges-fixes/{$edg->id}");
        $this->assertNull(ChargeFixe::withoutGlobalScopes()->find($edg->id));
    }

    public function test_droits_et_isolation(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $charge = $this->dans($b, fn () => ChargeFixe::create(['motif' => 'Loyer', 'montant' => 1_000_000, 'jour' => 5, 'mode' => 'virement']));
        $vendeur = $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn',
            'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', 'Vendeur')->value('id')]));
        $this->actingAs($vendeur)->post('/charges-fixes', ['motif' => 'X', 'montant' => '1000', 'jour' => 1, 'mode' => 'especes'])->assertForbidden();
        $this->get('/tableau-de-bord')->assertDontSee('charge(s) fixe(s)');

        // Une autre boutique ne peut ni modifier la charge ni la payer
        [$autre, $adminAutre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $this->flushSession();
        $this->actingAs($adminAutre->fresh())->delete("/charges-fixes/{$charge->id}")->assertNotFound();
        $this->post('/depenses', ['charge_fixe_id' => $charge->id, 'motif' => 'Loyer', 'montant' => '1000', 'mode' => 'virement', 'date_depense' => now()->toDateString()])
            ->assertNotFound();
        $this->assertSame(0, Depense::withoutGlobalScopes()->count());
    }
}
