<?php

namespace Tests\Feature;

use App\Models\Annonce;
use App\Models\Client;
use App\Models\Demande;
use App\Models\PaiementLicence;
use App\Models\User;
use App\Support\Plateforme;
use Tests\TestCase;

/** Espace Propriétaire : installation, licences, utilisateurs, annonces, assistance. */
class ProprietaireTest extends TestCase
{
    private function proprietaire(): User
    {
        return User::create(['prenom' => 'Mamadou', 'nom' => 'Barry', 'email' => 'moi@editeur.gn', 'password' => 'secret123', 'est_super_admin' => true]);
    }

    public function test_installation_cree_le_compte_proprietaire_une_seule_fois(): void
    {
        $this->get('/connexion')->assertSee("Créer l'espace Propriétaire", false);
        $this->get('/installation')->assertOk();

        $this->post('/installation', [
            'societe' => 'Ma Société SARL', 'nom_logiciel' => 'Caisse Plus', 'societe_telephone' => '+224 620 00 00 00', 'prenom' => 'Mamadou', 'nom' => 'Barry',
            'email' => 'moi@masociete.gn', 'password' => 'MotDePasse2026', 'password_confirmation' => 'MotDePasse2026',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
        $this->assertTrue(User::where('email', 'moi@masociete.gn')->first()->est_super_admin);
        $this->assertSame('Ma Société SARL', Plateforme::get('societe'));
        $this->assertGreaterThan(0, \App\Models\Plan::count(), 'les formules sont créées');

        $this->post('/deconnexion');
        $this->get('/installation')->assertNotFound();
        $this->get('/connexion')->assertDontSee("Créer l'espace Propriétaire", false);
    }

    public function test_paiement_de_licence_prolonge_sans_perdre_de_jours_et_donne_un_recu(): void
    {
        [$b] = $this->creerBoutique();
        $b->update(['statut' => 'essai', 'abonnement_expire_le' => now()->addDays(10)->toDateString()]);
        $moi = $this->proprietaire();

        $this->actingAs($moi)->post("/admin/boutiques/{$b->id}/paiements", [
            'plan_id' => $b->plan_id, 'duree' => '12', 'montant' => '1 800 000', 'mode' => 'orange_money',
            'reference' => 'OM123', 'paye_le' => now()->toDateString(),
        ])->assertSessionHas('succes');

        $b->refresh();
        $this->assertSame('actif', $b->statut);
        $this->assertTrue($b->licencePayee());
        // la nouvelle période commence au lendemain de l'échéance en cours
        $this->assertSame(now()->addDays(11)->addMonthsNoOverflow(12)->subDay()->toDateString(), $b->abonnement_expire_le->toDateString());
        $p = PaiementLicence::first();
        $this->assertSame(1_800_000, $p->montant);
        $this->assertStringStartsWith('LIC-', $p->numero);

        $this->get("/admin/paiements/{$p->id}/recu")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get('/admin/paiements')->assertOk()->assertSee($p->numero);
        $this->get('/admin/paiements/export/excel')->assertOk()->assertDownload();
        $this->get("/admin/boutiques/{$b->id}")->assertOk()->assertSee($p->numero);

        // Paiement saisi par erreur : l'échéance est rétablie
        $this->delete("/admin/paiements/{$p->id}")->assertSessionHas('succes');
        $this->assertSame(now()->addDays(10)->toDateString(), $b->fresh()->abonnement_expire_le->toDateString());
        $this->assertSame('essai', $b->fresh()->statut);
    }

    public function test_licence_a_vie(): void
    {
        [$b] = $this->creerBoutique();
        $this->actingAs($this->proprietaire())->post("/admin/boutiques/{$b->id}/paiements", [
            'duree' => 'illimitee', 'montant' => '5 000 000', 'mode' => 'virement', 'paye_le' => now()->toDateString(),
        ]);
        $this->assertNull($b->fresh()->abonnement_expire_le);
        $this->assertTrue($b->fresh()->licencePayee());
    }

    public function test_le_proprietaire_suspend_un_utilisateur_et_reinitialise_son_mot_de_passe(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $moi = $this->proprietaire();

        $this->actingAs($moi)->post("/admin/utilisateurs/{$admin->id}/statut")->assertSessionHas('succes');
        $this->assertFalse($admin->fresh()->actif);
        $this->post('/deconnexion');
        $this->post('/connexion', ['email' => $admin->email, 'password' => 'secret123'])->assertSessionHasErrors('email');

        $this->actingAs($moi)->post("/admin/utilisateurs/{$admin->id}/statut");
        $this->post("/admin/utilisateurs/{$admin->id}/mot-de-passe")->assertSessionHas('identifiants');
        $nouveau = session('identifiants')['mot_de_passe'];
        $this->post('/deconnexion');
        $this->post('/connexion', ['email' => $admin->email, 'password' => $nouveau])->assertRedirect();
        $this->assertAuthenticatedAs($admin->fresh());

        // Un propriétaire ne peut pas être suspendu depuis cet écran
        $this->actingAs($moi)->post("/admin/utilisateurs/{$moi->id}/statut")->assertForbidden();
    }

    public function test_annonce_ciblee_visible_puis_marquee_comme_lue(): void
    {
        [$b1, $u1] = $this->creerBoutique('Boutique A', 'a@test.gn');
        [$b2, $u2] = $this->creerBoutique('Boutique B', 'b@test.gn');
        $moi = $this->proprietaire();

        $this->actingAs($moi)->post('/admin/annonces', ['titre' => 'Maintenance dimanche', 'contenu' => 'Coupure de 2 h.', 'type' => 'maintenance', 'boutique_id' => $b1->id, 'publier' => 1]);
        $this->post('/admin/annonces', ['titre' => 'Brouillon secret', 'contenu' => '…', 'type' => 'nouveaute']);
        $annonce = Annonce::where('titre', 'Maintenance dimanche')->first();

        $this->actingAs($u1)->get('/tableau-de-bord')->assertSee('Maintenance dimanche')->assertDontSee('Brouillon secret');
        $this->actingAs($u2)->get('/tableau-de-bord')->assertDontSee('Maintenance dimanche');

        $this->actingAs($u1)->post("/nouveautes/{$annonce->id}/lue");
        $this->get('/tableau-de-bord')->assertDontSee("J'ai compris", false);
        $this->get('/nouveautes')->assertOk()->assertSee('Maintenance dimanche');
    }

    public function test_demande_d_assistance_et_reponse_du_proprietaire(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$autre, $autreAdmin] = $this->creerBoutique('Autre', 'autre@test.gn');
        $moi = $this->proprietaire();

        $this->actingAs($admin)->post('/assistance', ['sujet' => 'Reçu illisible', 'categorie' => 'probleme', 'contenu' => 'Le ticket sort vide.'])->assertRedirect();
        $d = Demande::first();
        $this->assertFalse($d->lue_proprietaire);

        $this->actingAs($autreAdmin)->get("/assistance/{$d->id}")->assertNotFound();

        $this->actingAs($moi)->get('/admin')->assertSee('Reçu illisible');
        $this->get("/admin/demandes/{$d->id}")->assertOk();
        $this->post("/admin/demandes/{$d->id}/messages", ['contenu' => 'Vérifiez le papier thermique.'])->assertSessionHas('succes');
        $this->assertSame('repondue', $d->fresh()->statut);

        $this->actingAs($admin)->get('/assistance')->assertSee('Réponse');
        $this->get("/assistance/{$d->id}")->assertSee('Vérifiez le papier thermique.');
        $this->assertTrue($d->fresh()->lue_boutique);
    }

    public function test_licence_expiree_laisse_acceder_a_l_assistance_et_a_la_licence(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['abonnement_expire_le' => now()->subDay()->toDateString()]);
        $this->actingAs($admin)->get('/produits')->assertRedirect(route('abonnement'));
        $this->get('/abonnement')->assertOk();
        $this->get('/assistance/nouvelle')->assertOk();
    }

    public function test_lien_whatsapp_avec_indicatif_guineen(): void
    {
        $this->assertSame('https://wa.me/224622000000?text=Bonjour%20%21', lien_whatsapp('622 00 00 00', 'Bonjour !'));
        $this->assertSame('https://wa.me/224622000000', lien_whatsapp('+224 622-00-00-00'));
        $this->assertNull(lien_whatsapp(''));
    }

    public function test_actions_du_proprietaire_tracees_et_relance_whatsapp(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['responsable_nom' => 'M. Bah', 'responsable_telephone' => '622 11 22 33']);
        $moi = $this->proprietaire();

        $this->actingAs($moi)->post("/admin/boutiques/{$b->id}/paiements", [
            'duree' => '3', 'montant' => '450 000', 'mode' => 'especes', 'paye_le' => now()->toDateString(),
        ]);
        $this->post("/admin/boutiques/{$b->id}/statut");
        $this->post("/admin/utilisateurs/{$admin->id}/mot-de-passe")->assertSessionHas('identifiants.nom', 'Admin');

        $this->get("/admin/boutiques/{$b->id}")->assertOk()
            ->assertSee('Boutique suspendue par le propriétaire')
            ->assertSee('Mot de passe provisoire créé pour')
            ->assertSee('Paiement LIC-', false)
            ->assertSee('https://wa.me/224622112233', false);
    }

    public function test_alerte_si_le_proprietaire_garde_le_mot_de_passe_par_defaut(): void
    {
        $moi = User::create(['prenom' => 'A', 'nom' => 'B', 'email' => 'x@y.gn', 'password' => config('gestion.super_admin.password'), 'est_super_admin' => true]);
        $this->actingAs($moi)->get('/admin')->assertSee('mot de passe par défaut');
        $moi->update(['password' => 'MonPropreMdp2026']);
        $this->actingAs($moi->fresh())->get('/admin')->assertDontSee('mot de passe par défaut');
        $this->get('/profil')->assertOk();
    }

    public function test_page_mot_de_passe_oublie(): void
    {
        Plateforme::enregistrer(['societe' => 'Ma Société SARL', 'whatsapp' => '620 00 00 00']);
        $this->get('/connexion')->assertSee('Mot de passe oublié ?');
        $this->get('/mot-de-passe-oublie')->assertOk()->assertSee('Ma Société SARL')->assertSee('https://wa.me/224620000000', false);
    }

    public function test_adresse_de_residence_du_client(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $this->actingAs($admin)->post('/clients', ['nom' => 'Camara', 'quartier' => 'Hamdallaye', 'commune' => 'Ratoma', 'ville' => 'Conakry', 'adresse' => 'Près du rond-point'])
            ->assertRedirect();
        $c = $this->dans($b, fn () => Client::first());
        $this->assertSame('Près du rond-point, Hamdallaye, Ratoma, Conakry', $c->residence());
        $this->get("/clients/{$c->id}")->assertSee('Hamdallaye');
    }
}
