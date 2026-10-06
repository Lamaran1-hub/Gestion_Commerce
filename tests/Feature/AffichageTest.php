<?php

namespace Tests\Feature;

use App\Models\Client;
use Tests\TestCase;

/** Détails d'affichage relevés en testant l'application dans le navigateur. */
class AffichageTest extends TestCase
{
    public function test_telephones_affiches_au_meme_format(): void
    {
        $this->assertSame('+224 623 95 80 50', numero_affiche('623958050'));
        $this->assertSame('+224 624 12 34 56', numero_affiche('624 12 34 56'));
        $this->assertSame('+224 628 33 44 55', numero_affiche('+224 628 33 44 55'));
        $this->assertSame('+33 6 11', numero_affiche('+33 6 11'), 'numéro étranger laissé tel quel');
        $this->assertSame('622 00 00 00', numero_local('+224 622 00 00 00'));

        [$b, $admin] = $this->creerBoutique();
        $this->dans($b, fn () => Client::create(['nom' => 'Kaba', 'telephone' => '623958050']));
        $this->actingAs($admin)->get('/clients')->assertSee('+224 623 95 80 50');
    }

    public function test_indicatif_pas_en_double_pour_le_payeur_de_la_licence(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $admin->update(['telephone' => '+224 622 00 00 00']);
        $this->actingAs($admin)->get('/abonnement')->assertOk()
            ->assertSee('value="622 00 00 00"', false)->assertDontSee('value="+224 622 00 00 00"', false);
    }

    public function test_extensions_d_assombrissement_desactivees(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->get('/tableau-de-bord')->assertSee('<meta name="darkreader-lock">', false);
        $this->post('/deconnexion');
        $this->get('/connexion')->assertSee('<meta name="darkreader-lock">', false);
    }
}
