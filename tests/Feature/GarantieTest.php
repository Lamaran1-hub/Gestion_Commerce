<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\NumeroSerie;
use App\Models\Vente;
use App\Services\VenteService;
use Tests\TestCase;

class GarantieTest extends TestCase
{
    private function venteTelephones($b, $admin, int $quantite = 2): Vente
    {
        $p = $this->produit($b, ['designation' => 'Téléphone Tecno Spark', 'garantie_mois' => 12, 'suivi_serie' => true], 10);
        $c = $this->dans($b, fn () => Client::firstOrCreate(['telephone' => '622000333'], ['nom' => 'Barry', 'prenom' => 'Aïssatou']));

        return $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => $quantite]], 'mode' => 'especes']), $admin);
    }

    public function test_garantie_figee_et_numeros_imprimes(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $v = $this->venteTelephones($b, $admin)->load('lignes');
        $l = $v->lignes->first();
        $this->assertSame(12, $l->garantie_mois);
        $this->assertSame(now()->addMonthsNoOverflow(12)->toDateString(), $l->garantieJusquau()->toDateString());

        // Changer la fiche produit ne change pas la garantie déjà donnée
        $l->produit->update(['garantie_mois' => 6]);
        $this->assertSame(12, $l->fresh()->garantie_mois);

        $this->actingAs($admin)->get("/ventes/{$v->id}")->assertSee('2 numéro(s) de série à noter')->assertSee('N° de série / IMEI 2');

        // Trop de numéros, doublon dans la saisie : refusés
        $this->post("/ventes/{$v->id}/numeros-serie", ['series' => [$l->id => ['A1', 'A2', 'A3']]])->assertSessionHas('erreur', fn ($m) => str_contains($m, '3 numéros pour 2'));
        $this->post("/ventes/{$v->id}/numeros-serie", ['series' => [$l->id => ['A1', 'a-1']]])->assertSessionHas('erreur', fn ($m) => str_contains($m, 'saisi deux fois'));

        $this->post("/ventes/{$v->id}/numeros-serie", ['series' => [$l->id => ['3561 2345 6789 012', '356123456789013']]])->assertSessionHas('succes');
        $this->assertSame(['356123456789012', '356123456789013'], $this->dans($b, fn () => NumeroSerie::orderBy('id')->pluck('numero')->all()));
        $this->get("/ventes/{$v->id}")->assertDontSee('numéro(s) de série à noter');
        $this->get("/ventes/{$v->id}/recu")->assertSee('N° série : 356123456789012, 356123456789013')->assertSee('Garantie 12 mois, jusqu');
        $this->get("/ventes/{$v->id}/facture")->assertOk();

        // Le même appareil ne se vend pas deux fois
        $v2 = $this->venteTelephones($b, $admin, 1)->load('lignes');
        $this->post("/ventes/{$v2->id}/numeros-serie", ['series' => [$v2->lignes->first()->id => ['356123456789012']]])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, "déjà été vendu (vente {$v->numero})"));
    }

    public function test_recherche_de_garantie(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $v = $this->venteTelephones($b, $admin, 1)->load('lignes');
        $this->actingAs($admin)->post("/ventes/{$v->id}/numeros-serie", ['series' => [$v->lignes->first()->id => ['356123456789012']]]);

        $this->get('/garanties?q=789012')->assertOk()->assertSee('Téléphone Tecno Spark')->assertSee($v->numero)->assertSee('Garanti jusqu');
        $this->get('/garanties?q=622000333')->assertSee('Téléphone Tecno Spark');
        $this->get('/garanties?q=999999')->assertSee('Aucun article trouvé');

        // Un an et un jour plus tard : expirée
        $v->update(['date_vente' => now()->subMonths(13)]);   // vendu il y a 13 mois : garantie de 12 mois expirée
        $this->get('/garanties?q=789012')->assertSee('Expirée le');

        // Autre boutique : rien
        [, $admin2] = $this->creerBoutique('Autre boutique', 'autre@test.gn');
        $this->actingAs($admin2)->get('/garanties?q=789012')->assertSee('Aucun article trouvé');
        $this->post("/ventes/{$v->id}/numeros-serie", ['series' => [1 => ['X']]])->assertNotFound();
    }
}
