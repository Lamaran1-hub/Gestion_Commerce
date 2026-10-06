<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Tests\TestCase;

class AccesTest extends TestCase
{
    private function utilisateur($b, string $role, string $email): User
    {
        return User::create(['boutique_id' => $b->id, 'role_id' => Role::withoutGlobalScopes()->where(['boutique_id' => $b->id, 'nom' => $role])->value('id'),
            'prenom' => 'Test', 'nom' => $role, 'email' => $email, 'password' => 'secret123']);
    }

    public function test_un_vendeur_n_accede_pas_a_l_administration(): void
    {
        [$b] = $this->creerBoutique();
        $vendeur = $this->utilisateur($b, 'Vendeur', 'v@test.gn');

        $this->actingAs($vendeur);
        $this->get('/caisse')->assertOk();
        $this->get('/tableau-de-bord')->assertRedirect(route('ventes.create'));
        $this->get('/parametres')->assertForbidden();
        $this->get('/utilisateurs')->assertForbidden();
        $this->get('/depenses')->assertForbidden();
        $this->get('/rapports')->assertForbidden();
        $this->get('/admin')->assertForbidden();
    }

    public function test_un_vendeur_ne_voit_pas_les_prix_d_achat(): void
    {
        [$b] = $this->creerBoutique();
        $this->produit($b, ['prix_achat' => 123_456]);
        $vendeur = $this->utilisateur($b, 'Vendeur', 'v@test.gn');

        $this->actingAs($vendeur)->get('/produits')->assertOk()->assertDontSee('123 456');
    }

    public function test_abonnement_expire_bloque_la_boutique(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['abonnement_expire_le' => now()->subDay()]);

        $this->actingAs($admin)->get('/caisse')->assertRedirect(route('abonnement'));
        $this->get('/abonnement')->assertOk()->assertSee('licence a expiré');
    }

    public function test_boutique_suspendue_bloquee_puis_reactivee_par_le_super_admin(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['statut' => 'suspendu']);
        $this->actingAs($admin)->get('/produits')->assertRedirect(route('abonnement'));

        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $super = User::where('est_super_admin', true)->first();
        $this->actingAs($super)->post("/admin/boutiques/{$b->id}/statut")->assertSessionHas('succes');
        $this->actingAs($super)->post("/admin/boutiques/{$b->id}/prolonger", ['mois' => 3]);

        $this->assertTrue($b->fresh()->estActive());
        $this->actingAs($admin->fresh())->get('/produits')->assertOk();
    }

    public function test_compte_desactive_ne_peut_pas_se_connecter(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $admin->update(['actif' => false]);

        $this->post('/connexion', ['email' => $admin->email, 'password' => 'secret123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_le_changement_de_mot_de_passe_provisoire_est_conseille_mais_pas_impose(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $admin->update(['doit_changer_mot_de_passe' => true]);

        $this->post('/connexion', ['email' => $admin->email, 'password' => 'secret123'])->assertRedirect();
        $this->get('/produits')->assertOk()->assertSee('mot de passe provisoire');
        $this->put('/profil/mot-de-passe', ['mot_de_passe_actuel' => 'secret123', 'password' => 'nouveau2026', 'password_confirmation' => 'nouveau2026'])->assertRedirect();
        $this->get('/produits')->assertOk()->assertDontSee('mot de passe provisoire');
    }

    public function test_limite_d_utilisateurs_de_la_formule(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->plan->update(['max_utilisateurs' => 1]);

        $this->actingAs($admin)->post('/utilisateurs', [
            'prenom' => 'Awa', 'nom' => 'Touré', 'email' => 'awa@test.gn', 'role_id' => Role::withoutGlobalScopes()->where('boutique_id', $b->id)->where('nom', 'Vendeur')->value('id'),
            'password' => 'secret123', 'password_confirmation' => 'secret123', 'actif' => 1,
        ])->assertSessionHas('erreur');
        $this->assertSame(1, User::where('boutique_id', $b->id)->count());
    }

    public function test_le_dernier_administrateur_ne_peut_pas_etre_retrogade(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $vendeur = Role::withoutGlobalScopes()->where('boutique_id', $b->id)->where('nom', 'Vendeur')->value('id');

        $this->actingAs($admin)->put("/utilisateurs/{$admin->id}", [
            'prenom' => 'Admin', 'nom' => 'X', 'email' => $admin->email, 'role_id' => $vendeur, 'actif' => 1,
        ])->assertSessionHas('erreur');
        $this->assertTrue($admin->fresh()->estAdministrateurBoutique());
    }

    public function test_un_admin_ne_peut_ni_se_desactiver_ni_changer_son_role_meme_avec_un_autre_admin(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $roleAdmin = $admin->role_id;
        $vendeur = Role::withoutGlobalScopes()->where('boutique_id', $b->id)->where('nom', 'Vendeur')->value('id');
        // Un second administrateur existe : la règle « dernier administrateur » ne protège plus
        $this->dans($b, fn () => User::create(['boutique_id' => $b->id, 'prenom' => 'Second', 'nom' => 'Admin', 'email' => 'second@test.gn',
            'password' => 'secret123', 'role_id' => $roleAdmin, 'actif' => true]));
        $champs = ['prenom' => 'Admin', 'nom' => 'X', 'email' => $admin->email];

        $this->actingAs($admin)->put("/utilisateurs/{$admin->id}", $champs + ['role_id' => $roleAdmin])   // case « actif » décochée
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'désactiver votre propre compte'));
        $this->put("/utilisateurs/{$admin->id}", $champs + ['role_id' => $vendeur, 'actif' => 1])
            ->assertSessionHas('erreur', fn ($m) => str_contains($m, 'changer votre propre rôle'));
        $admin->refresh();
        $this->assertTrue($admin->actif && $admin->estAdministrateurBoutique());

        // Le formulaire verrouille ces deux champs ; enregistrer son nom reste possible
        $this->get("/utilisateurs/{$admin->id}/edit")->assertOk()->assertSee('(votre compte)');
        $this->put("/utilisateurs/{$admin->id}", ['prenom' => 'Mamadou'] + $champs + ['role_id' => $roleAdmin, 'actif' => 1])->assertSessionHas('succes');
        $this->assertSame('Mamadou', $admin->fresh()->prenom);

        // Un autre administrateur peut, lui, désactiver ce compte
        $second = User::where('email', 'second@test.gn')->first();
        $this->actingAs($second)->put("/utilisateurs/{$admin->id}", $champs + ['role_id' => $roleAdmin])->assertSessionHas('succes');
        $this->assertFalse($admin->fresh()->actif);
    }

    public function test_un_admin_ne_peut_pas_modifier_l_utilisateur_d_une_autre_boutique(): void
    {
        [$a, $adminA] = $this->creerBoutique('A', 'a@test.gn');
        [$b, $adminB] = $this->creerBoutique('B', 'b@test.gn');

        $this->actingAs($adminB)->get("/utilisateurs/{$adminA->id}/modifier")->assertNotFound();
    }

    public function test_inscription_d_une_nouvelle_boutique(): void
    {
        $this->post('/creer-ma-boutique', $this->humain() + [
            'boutique_nom' => 'Alimentation Bah', 'boutique_telephone' => '+224 620 00 00 00', 'ville' => 'Labé',
            'prenom' => 'Thierno', 'nom' => 'Bah', 'email' => 'thierno@test.gn', 'password' => 'motdepasse1', 'password_confirmation' => 'motdepasse1',
        ])->assertRedirect(route('parametres.edit'));

        $user = User::where('email', 'thierno@test.gn')->first();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->estAdministrateurBoutique());
        $this->assertSame('essai', $user->boutique->statut);
        $this->assertSame(4, Role::withoutGlobalScopes()->where('boutique_id', $user->boutique_id)->count());
    }
}
