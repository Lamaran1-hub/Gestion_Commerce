<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/** Centre d'aide : guides adaptés à la formule et aux droits. */
class AideTest extends TestCase
{
    public function test_chaque_guide_pointe_vers_un_ecran_existant(): void
    {
        $rubriques = array_keys(config('aide.rubriques'));
        foreach (config('aide.guides') as $cle => $g) {
            $this->assertContains($g['rubrique'], $rubriques, "Rubrique inconnue pour le guide {$cle}");
            $this->assertTrue(\Route::has($g['lien']), "Route inconnue pour le guide {$cle}");
            if (! empty($g['fonction'])) {
                $this->assertArrayHasKey($g['fonction'], config('gestion.fonctions'), "Fonction inconnue pour le guide {$cle}");
            }
        }
    }

    public function test_l_administrateur_voit_les_guides_avec_les_liens(): void
    {
        [, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->get('/aide')->assertOk()
            ->assertSee('Centre d')->assertSee('Encaisser une vente')->assertSee('Ouvrir sa vitrine en ligne')
            ->assertSee(route('parametres.edit'))->assertDontSee('Formule supérieure');
    }

    public function test_fonctions_hors_formule_signalees(): void
    {
        [, $admin] = $this->creerBoutique('Petite', 'petite@test.gn', false); // Démarrage
        $this->actingAs($admin)->get('/aide')->assertOk()->assertSee('Formule supérieure')->assertSee(route('abonnement'));
    }

    public function test_le_vendeur_n_a_pas_de_lien_vers_les_ecrans_reserves(): void
    {
        [$b] = $this->creerBoutique();
        $vendeur = User::create(['boutique_id' => $b->id, 'role_id' => Role::withoutGlobalScopes()->where(['boutique_id' => $b->id, 'nom' => 'Vendeur'])->value('id'),
            'prenom' => 'Awa', 'nom' => 'Vendeur', 'email' => 'awa@test.gn', 'password' => 'secret123']);

        $this->actingAs($vendeur)->get('/aide')->assertOk()->assertSee('Encaisser une vente')
            ->assertSee(route('ventes.create'))->assertDontSee(route('parametres.edit'))->assertSee('réservé à un responsable');
    }
}
