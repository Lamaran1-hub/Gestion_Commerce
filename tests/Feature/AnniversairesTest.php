<?php

namespace Tests\Feature;

use App\Models\Client;
use Carbon\Carbon;
use Tests\TestCase;

/** Anniversaires des clients : calcul, liste de la semaine, vœux WhatsApp (un par an), repères au tableau de bord et à la caisse. */
class AnniversairesTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_calcul_du_prochain_anniversaire(): void
    {
        Carbon::setTestNow('2026-12-30 10:00');
        $c = new Client(['nom' => 'X', 'date_naissance' => '1990-01-02']);
        $this->assertSame('2027-01-02', $c->prochainAnniversaire()->toDateString(), 'Passage à l\'année suivante');
        $this->assertSame(3, $c->joursAvantAnniversaire());

        Carbon::setTestNow('2027-02-20 10:00');   // 2027 n'est pas bissextile
        $bissextile = new Client(['nom' => 'Y', 'date_naissance' => '2000-02-29']);
        $this->assertSame('2027-02-28', $bissextile->prochainAnniversaire()->toDateString());

        Carbon::setTestNow('2026-05-10 08:00');
        $this->assertSame(0, (new Client(['nom' => 'Z', 'date_naissance' => '1985-05-10']))->joursAvantAnniversaire());
        $this->assertNull((new Client(['nom' => 'W']))->joursAvantAnniversaire());
    }

    public function test_liste_de_la_semaine_et_voeux_une_fois_par_an(): void
    {
        Carbon::setTestNow('2026-05-10 08:00');
        [$b, $admin] = $this->creerBoutique();
        $b->update(['cadeau_anniversaire' => '-10 % sur votre achat cette semaine']);
        $aujourdhui = $this->dans($b, fn () => Client::create(['nom' => 'Bah', 'prenom' => 'Mariama', 'telephone' => '620112233', 'date_naissance' => '1990-05-10']));
        $this->dans($b, fn () => Client::create(['nom' => 'Sow', 'telephone' => '620445566', 'date_naissance' => '1988-05-14']));
        $this->dans($b, fn () => Client::create(['nom' => 'Loin', 'telephone' => '620778899', 'date_naissance' => '1988-08-01']));

        $this->actingAs($admin)->get('/clients?segment=anniversaires')->assertOk()
            ->assertSee('Mariama Bah')->assertSee('Sow')->assertDontSee('Loin</a>', false)
            ->assertSee("Aujourd'hui")->assertSee('14 mai')->assertSee('Souhaiter');
        $this->get('/tableau-de-bord')->assertOk()->assertSee("Anniversaire aujourd'hui", false)->assertSee('Mariama Bah')->assertDontSee('Sow</strong>', false);

        $r = $this->post("/clients/{$aujourdhui->id}/souhaiter");
        $lien = urldecode($r->headers->get('Location'));
        $this->assertStringStartsWith('https://wa.me/224620112233?text=', $r->headers->get('Location'));
        $this->assertStringContainsString('Joyeux anniversaire Mariama', $lien);
        $this->assertStringContainsString('-10 % sur votre achat cette semaine', $lien);
        $this->assertSame('2026-05-10', $aujourdhui->fresh()->dernier_voeu_le->toDateString());

        // Une seule fois par an ; le bandeau du tableau de bord disparaît
        $this->post("/clients/{$aujourdhui->id}/souhaiter")->assertSessionHas('erreur', fn ($m) => str_contains($m, 'déjà été envoyés'));
        $this->get('/tableau-de-bord')->assertDontSee("Anniversaire aujourd'hui", false);
        $this->get('/clients?segment=anniversaires')->assertSee('vœux envoyés');
    }

    public function test_saisie_de_la_date_et_repere_a_la_caisse(): void
    {
        Carbon::setTestNow('2026-05-10 08:00');
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->post('/clients', ['nom' => 'Diallo', 'telephone' => '620999999', 'date_naissance' => '1995-05-12'])->assertSessionHasNoErrors();
        $c = Client::withoutGlobalScopes()->where('nom', 'Diallo')->sole();
        $this->assertSame('1995-05-12', $c->date_naissance->toDateString());

        $this->post('/clients', ['nom' => 'Futur', 'date_naissance' => '2030-01-01'])->assertSessionHasErrors('date_naissance');
        $this->get('/caisse')->assertOk()->assertSee('data-anniv="2"', false)->assertSee('id="annivClient"', false);
    }
}
