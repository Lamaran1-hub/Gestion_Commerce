<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParametresTest extends TestCase
{
    public function test_la_boutique_ajoute_son_logo_et_ses_coordonnees(): void
    {
        Storage::fake('public');
        [$b, $admin] = $this->creerBoutique();

        $this->actingAs($admin)->put('/parametres', [
            'nom' => 'Quincaillerie Camara', 'telephone' => '+224 620 12 34 56', 'ville' => 'Kindia', 'tva_taux' => 18,
            'couleur' => '#8A3B12', 'tva_active' => 1, 'logo' => UploadedFile::fake()->image('logo.png', 300, 300),
        ])->assertSessionHas('succes');

        $b->refresh();
        $this->assertSame('Quincaillerie Camara', $b->nom);
        $this->assertTrue($b->tva_active);
        $this->assertStringStartsWith("boutiques/{$b->id}/", $b->logo);
        Storage::disk('public')->assertExists($b->logo);

        $this->get('/tableau-de-bord')->assertSee($b->logoUrl(), false)->assertSee('--marque: #8A3B12', false);

        $this->put('/parametres', ['nom' => 'Quincaillerie Camara', 'tva_taux' => 18, 'couleur' => '#8A3B12', 'supprimer_logo' => 1]);
        $this->assertNull($b->fresh()->logo);
    }

    public function test_un_fichier_qui_n_est_pas_une_image_est_refuse(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->put('/parametres', [
            'nom' => 'X', 'tva_taux' => 18, 'couleur' => '#000000', 'logo' => UploadedFile::fake()->create('virus.php', 10, 'application/x-php'),
        ])->assertSessionHasErrors('logo');
    }
}
