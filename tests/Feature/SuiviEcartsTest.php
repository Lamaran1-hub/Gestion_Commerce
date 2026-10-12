<?php

namespace Tests\Feature;

use App\Models\ClotureCaisse;
use App\Models\JournalActivite;
use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

/** Écarts de caisse par caissier : manques répétés signalés, fonds différents, réouvertures. */
class SuiviEcartsTest extends TestCase
{
    private function cloture($b, User $u, string $jour, int $ecart, int $fond = 0, ?int $laisse = null): void
    {
        $this->dans($b, fn () => ClotureCaisse::create(['user_id' => $u->id, 'jour' => $jour, 'nb_ventes' => 1, 'total_ventes' => 100_000,
            'encaissements' => ['especes' => 100_000], 'sorties_especes' => 0, 'fond_caisse' => $fond, 'especes_theoriques' => 100_000 + $fond,
            'especes_comptees' => 100_000 + $fond + $ecart, 'laisse_en_caisse' => $laisse, 'ecart' => $ecart, 'motif_ecart' => $ecart ? 'Erreur de rendu de monnaie' : null, 'cloturee_par' => $u->id]));
    }

    public function test_bilan_par_caissier_et_rappel(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(18, 0));
        [$b, $admin] = $this->creerBoutique();
        $role = Role::where('nom', 'Vendeur')->value('id');
        [$awa, $ibrahima] = $this->dans($b, fn () => [
            User::create(['boutique_id' => $b->id, 'prenom' => 'Awa', 'nom' => 'Sow', 'email' => 'awa@test.gn', 'password' => 'secret123', 'actif' => true, 'role_id' => $role]),
            User::create(['boutique_id' => $b->id, 'prenom' => 'Ibrahima', 'nom' => 'Diallo', 'email' => 'ibra@test.gn', 'password' => 'secret123', 'actif' => true, 'role_id' => $role]),
        ]);
        // Awa : 3 manques en novembre, et un fond déclaré plus bas que ce qu'elle avait laissé la veille
        $this->cloture($b, $awa, '2026-11-03', -20_000, 0, 50_000);
        $this->cloture($b, $awa, '2026-11-04', -15_000, 30_000, 50_000);
        $this->cloture($b, $awa, '2026-11-05', 0, 50_000);
        $this->cloture($b, $awa, '2026-11-12', -5_000);
        // Ibrahima : un excédent, un manque isolé
        $this->cloture($b, $ibrahima, '2026-11-10', 2_000);
        $this->cloture($b, $ibrahima, '2026-11-11', -1_000);
        $this->dans($b, fn () => JournalActivite::noter('caisse', 'Réouverture de la caisse de Ibrahima Diallo du 11/11/2026 par Admin'), $admin);

        $this->actingAs($admin)->get('/clotures?du=2026-11-01&au=2026-11-20')->assertOk()
            ->assertSee('Écarts par caissier')->assertSeeInOrder(['Awa Sow', 'À surveiller', 'Ibrahima Diallo'])
            ->assertSee('− 40 000 GNF')->assertSee('1 fond(s) différent(s) de la veille')->assertSee('1 réouverture(s) de caisse');
        $this->get('/tableau-de-bord')->assertSee('Manques de caisse répétés (30 jours)')->assertSee('Awa Sow — 3 manque(s), 40 000 GNF')->assertDontSee('Ibrahima Diallo —');

        // Hors période : plus rien à signaler
        $this->get('/clotures?du=2026-11-13&au=2026-11-20')->assertDontSee('Écarts par caissier');

        // Le vendeur ne voit ni le bilan des autres ni le rappel
        $this->flushSession();
        $this->actingAs($ibrahima->fresh())->get('/clotures?du=2026-11-01&au=2026-11-20')->assertOk()->assertDontSee('Écarts par caissier')->assertDontSee('Awa Sow');
        $this->get('/tableau-de-bord')->assertDontSee('Manques de caisse répétés');
    }
}
