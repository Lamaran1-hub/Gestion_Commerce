<?php

namespace Tests\Feature;

use App\Models\Devis;
use App\Models\JournalActivite;
use App\Models\Role;
use App\Models\User;
use App\Models\Vente;
use App\Services\Registre;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** Accord du responsable : remise au-delà du maximum (ou par un vendeur sans droit) autorisée par un code personnel. */
class AccordResponsableTest extends TestCase
{
    private function equipe(): array
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['remise_max_pct' => 10]);
        $creer = fn (string $prenom, string $email, string $role) => $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => $prenom, 'nom' => 'Test',
            'email' => $email, 'password' => 'secret123', 'actif' => true, 'role_id' => Role::where('nom', $role)->value('id')]));
        $gestionnaire = $creer('Mamadou', 'gest@test.gn', 'Gestionnaire');
        $vendeur = $creer('Awa', 'awa@test.gn', 'Vendeur');
        $p = $this->produit($b, ['prix_achat' => 100_000], 20);   // vendu 300 000

        return [$b, $admin, $gestionnaire, $vendeur, $p];
    }

    private function vente(int $produitId, array $extra = []): array
    {
        return $extra + ['lignes' => [['produit_id' => $produitId, 'quantite' => 1]], 'mode' => 'especes', 'remise' => '45 000'];
    }

    public function test_le_responsable_choisit_son_code_et_accorde_une_remise_au_dela_du_maximum(): void
    {
        [$b, $admin, $gestionnaire, $vendeur, $p] = $this->equipe();

        // Sans code choisi : pas de bloc à la caisse, le maximum s'applique comme avant
        $this->actingAs($gestionnaire)->get('/caisse')->assertDontSee('id="blocAccord"', false);
        $this->post('/ventes', $this->vente($p->id))->assertSessionHas('erreur', fn ($m) => str_contains($m, 'maximum autorisé') && ! str_contains($m, 'responsable'));

        // L'administrateur choisit son code dans son profil (mot de passe demandé, code trop simple refusé)
        $this->flushSession();
        $this->actingAs($admin)->get('/profil')->assertSee('id="codeAccord"', false)->assertSee('Pas de code');
        $this->put('/profil/code-accord', ['code_accord' => '2580', 'code_accord_confirmation' => '2580', 'mot_de_passe_code' => 'faux'])->assertSessionHasErrors('mot_de_passe_code');
        $this->put('/profil/code-accord', ['code_accord' => '1234', 'code_accord_confirmation' => '1234', 'mot_de_passe_code' => 'secret123'])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'trop facile'));
        $this->put('/profil/code-accord', ['code_accord' => '2580', 'code_accord_confirmation' => '2580', 'mot_de_passe_code' => 'secret123'])->assertSessionHas('succes');
        $this->assertNotSame('2580', $admin->fresh()->code_accord);   // haché
        $this->get('/profil')->assertSee('Code choisi');
        // Le gestionnaire ne gère pas les paramètres : il n'a pas de code d'accord
        $this->flushSession();
        $this->actingAs($gestionnaire)->get('/profil')->assertDontSee('id="codeAccord"', false);
        $this->put('/profil/code-accord', ['code_accord' => '7391', 'code_accord_confirmation' => '7391', 'mot_de_passe_code' => 'secret123'])->assertSessionHas('erreur');

        // À la caisse : le bloc est prêt, le refus le mentionne
        $this->get('/caisse')->assertSee('id="blocAccord"', false)->assertSee('Admin Boutique Test');
        $this->post('/ventes', $this->vente($p->id))->assertSessionHas('erreur', fn ($m) => str_contains($m, 'un responsable doit l\'accorder avec son code'));
        // Code faux : refusé et noté
        $this->post('/ventes', $this->vente($p->id, ['responsable_id' => $admin->id, 'code_accord' => '0000']))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Code du responsable incorrect') && str_contains($m, 'Encore 4 essai(s)'));
        $this->assertSame(0, $this->dans($b, fn () => Vente::count()));
        // Bon code : 15 % de remise accordés
        $this->post('/ventes', $this->vente($p->id, ['responsable_id' => $admin->id, 'code_accord' => '2580']))->assertSessionHas('succes');
        $v = $this->dans($b, fn () => Vente::sole());
        $this->assertSame([45_000, 255_000, $admin->id], [$v->remise, $v->total_ttc, $v->remise_accordee_par]);
        $this->get("/ventes/{$v->id}")->assertSee('id="remiseAccordee"', false)->assertSee('accordée par Admin Boutique Test');
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', "Vente {$v->numero} de 255 000 GNF — remise de 45 000 GNF accordée par Admin Boutique Test%")->exists()));
        $this->assertTrue($this->dans($b, fn () => JournalActivite::where('description', 'like', 'Code faux de Admin Boutique Test saisi à la caisse de Mamadou Test')->exists()));

        // Même avec le code, on ne vend pas à perte
        $this->post('/ventes', $this->vente($p->id, ['remise' => '250 000', 'responsable_id' => $admin->id, 'code_accord' => '2580']))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'sous le prix d\'achat'));

        $this->assertTrue(app(Registre::class)->verifier($b->fresh())['intact']);
    }

    public function test_vendeur_sans_droit_de_remise_et_devis(): void
    {
        [$b, $admin, $gestionnaire, $vendeur, $p] = $this->equipe();
        $admin->forceFill(['code_accord' => bcrypt('2580')])->save();

        // Sans code, la remise d'un vendeur est ignorée (comme avant) ; avec le code, elle passe, même sous le maximum
        $this->actingAs($vendeur)->get('/caisse')->assertSee('id="remise"', false)->assertSee('id="blocAccord"', false);
        $this->post('/ventes', $this->vente($p->id, ['remise' => '10 000']))->assertSessionHas('succes');
        $this->assertSame(0, $this->dans($b, fn () => Vente::latest('id')->first())->remise);
        $this->post('/ventes', $this->vente($p->id, ['remise' => '10 000', 'responsable_id' => $admin->id, 'code_accord' => '2580']))->assertSessionHas('succes');
        $v = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->assertSame([10_000, $admin->id], [$v->remise, $v->remise_accordee_par]);

        // Devis avec accord : la transformation en vente garde la remise accordée
        $this->post('/devis', $this->vente($p->id, ['responsable_id' => $admin->id, 'code_accord' => '2580']))->assertSessionHas('succes');
        $d = $this->dans($b, fn () => Devis::sole());
        $this->assertSame([45_000, $admin->id], [$d->remise, $d->remise_accordee_par]);
        $this->get("/devis/{$d->id}")->assertSee('accordée par Admin Boutique Test');
        $this->flushSession();
        $this->actingAs($admin)->post("/devis/{$d->id}/vente", ['mode' => 'especes'])->assertSessionHas('succes');
        $v2 = $this->dans($b, fn () => Vente::latest('id')->first());
        $this->assertSame([45_000, $admin->id], [$v2->remise, $v2->remise_accordee_par]);
    }

    public function test_trop_de_codes_faux_bloquent_la_saisie(): void
    {
        [$b, $admin, $gestionnaire, $vendeur, $p] = $this->equipe();
        $admin->forceFill(['code_accord' => bcrypt('2580')])->save();
        RateLimiter::clear('accord-responsable:'.$gestionnaire->id);
        $this->actingAs($gestionnaire);
        foreach (range(1, 5) as $i) {
            $this->post('/ventes', $this->vente($p->id, ['responsable_id' => $admin->id, 'code_accord' => '1111']));
        }
        // Même le bon code est refusé pendant le blocage
        $this->post('/ventes', $this->vente($p->id, ['responsable_id' => $admin->id, 'code_accord' => '2580']))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Trop de codes faux'));
        // Un responsable désactivé ne peut plus accorder
        RateLimiter::clear('accord-responsable:'.$gestionnaire->id);
        $admin->forceFill(['actif' => false])->save();
        $this->post('/ventes', $this->vente($p->id, ['responsable_id' => $admin->id, 'code_accord' => '2580']))
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'Choisissez le responsable'));
        $this->assertSame(0, $this->dans($b, fn () => Vente::count()));
        // Il peut retirer son code
        $admin->forceFill(['actif' => true])->save();
        $this->flushSession();
        $this->actingAs($admin->fresh())->delete('/profil/code-accord')->assertSessionHas('succes');
        $this->assertNull($admin->fresh()->code_accord);
    }
}
