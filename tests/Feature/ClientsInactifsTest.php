<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Vente;
use App\Services\VenteService;
use Tests\TestCase;

/** Segments de clientèle et invitation WhatsApp des clients qui ne reviennent plus. */
class ClientsInactifsTest extends TestCase
{
    /** Crée un client et une vente payée à la date donnée. */
    private function clientAvecAchat($b, $admin, string $nom, ?string $tel, \Carbon\Carbon $date, $produit): Client
    {
        $c = $this->dans($b, fn () => Client::create(['nom' => $nom, 'telephone' => $tel]));
        $v = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $c->id, 'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
        Vente::withoutGlobalScopes()->whereKey($v->id)->update(['date_vente' => $date]);

        return $c;
    }

    public function test_segment_a_relancer_et_invitation(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 50);
        $inactif = $this->clientAvecAchat($b, $admin, 'Bah', '620112233', now()->subDays(45), $p);
        $this->clientAvecAchat($b, $admin, 'Récent', '620445566', now()->subDays(3), $p);
        $this->clientAvecAchat($b, $admin, 'SansTel', null, now()->subDays(60), $p);
        $this->dans($b, fn () => Client::create(['nom' => 'JamaisAchete', 'telephone' => '620778899']));

        $this->actingAs($admin)->get('/clients?segment=a_relancer')->assertOk()
            ->assertSee('Bah')->assertDontSee('Récent')->assertDontSee('SansTel')->assertDontSee('JamaisAchete')
            ->assertSee('Inviter')->assertSee('il y a 1 mois');
        $this->get('/clients?segment=a_relancer&jours=60')->assertOk()->assertDontSee('Bah');

        // L'invitation ouvre WhatsApp avec un message personnalisé et sort le client de la liste pendant 14 jours
        $r = $this->post("/clients/{$inactif->id}/inviter");
        $r->assertRedirect();
        $this->assertStringStartsWith('https://wa.me/224620112233?text=', $r->headers->get('Location'));
        $this->assertStringContainsString(rawurlencode('Cela fait un moment'), $r->headers->get('Location'));
        $this->assertNotNull($inactif->fresh()->derniere_invitation_le);
        $this->get('/clients?segment=a_relancer')->assertDontSee('Bah');
        $this->post("/clients/{$inactif->id}/inviter")->assertSessionHas('erreur');
        $this->get("/clients/{$inactif->id}")->assertOk()->assertSee('Invité récemment');
    }

    public function test_segments_fideles_nouveaux_debiteurs_et_tri(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $p = $this->produit($b, [], 50);
        $fidele = $this->clientAvecAchat($b, $admin, 'Fidèle', '620000001', now()->subDays(5), $p);
        foreach ([10, 20] as $j) {
            $v = $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $fidele->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'mode' => 'especes']), $admin);
            Vente::withoutGlobalScopes()->whereKey($v->id)->update(['date_vente' => now()->subDays($j)]);
        }
        $debiteur = $this->dans($b, fn () => Client::create(['nom' => 'Débiteur', 'telephone' => '620000002']));
        $this->dans($b, fn () => app(VenteService::class)->creer(['client_id' => $debiteur->id, 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]], 'montant_recu' => 0]), $admin);

        $this->actingAs($admin)->get('/clients?segment=fideles')->assertOk()->assertSee('Fidèle')->assertDontSee('Débiteur</a>', false);
        $this->get('/clients?segment=debiteurs')->assertOk()->assertSee('Débiteur')->assertDontSee('Fidèle</a>', false);
        $this->get('/clients?segment=nouveaux')->assertOk()->assertSee('Fidèle')->assertSee('Débiteur');
        $this->get('/clients?tri=total')->assertOk()->assertSeeInOrder(['Fidèle', 'Débiteur']);
    }

    public function test_invitation_reservee_aux_formules_avec_relances(): void
    {
        [$b, $admin] = $this->creerBoutique('Petite', 'petite@test.gn', false);
        $b->plan->update(['fonctions' => ['hors_ligne']]);   // formule sans les relances WhatsApp
        $client = $this->dans($b, fn () => Client::create(['nom' => 'X', 'telephone' => '620112233']));

        $this->actingAs($admin->fresh())->post("/clients/{$client->id}/inviter")->assertForbidden();
        $this->assertNull($client->fresh()->derniere_invitation_le);
        $this->get('/clients?segment=a_relancer')->assertOk()->assertSee('inclus dans une formule supérieure');
    }
}
