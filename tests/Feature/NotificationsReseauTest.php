<?php

namespace Tests\Feature;

use App\Models\Devis;
use App\Notifications\CommandeVitrine;
use Tests\TestCase;

/** Une notification d'une boutique du réseau s'ouvre même quand on travaille dans une autre boutique. */
class NotificationsReseauTest extends TestCase
{
    public function test_notification_d_une_autre_boutique_du_reseau_bascule_sur_elle(): void
    {
        [$b, $admin] = $this->creerBoutique();
        $b->update(['plan_id' => \App\Models\Plan::where('nom', 'Commerce')->value('id')]);   // jusqu'à 3 boutiques
        $p = $this->produit($b, [], 10);
        $this->actingAs($admin)->post('/devis', ['client_nom' => 'Client Vitrine', 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);
        $devis = $this->dans($b, fn () => Devis::latest('id')->first());
        $admin->notify(new CommandeVitrine($devis));
        $notification = $admin->notifications()->first();
        $this->assertSame($b->id, $notification->data['boutique_id']);

        // L'administrateur ouvre un second point de vente : il y travaille désormais
        $this->post('/mes-boutiques', ['nom' => 'Boutique Madina', 'ville' => 'Conakry'])->assertRedirect('/tableau-de-bord');
        $this->get("/devis/{$devis->id}")->assertNotFound();   // fiche d'une autre boutique, normalement invisible

        // La notification le ramène dans la bonne boutique et la commande s'affiche
        $this->get("/notifications/{$notification->id}")->assertRedirect(route('devis.show', $devis));
        $this->get("/devis/{$devis->id}")->assertOk()->assertSee('Client Vitrine');
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_notification_d_une_boutique_inaccessible_ne_bascule_pas(): void
    {
        [$b, $admin] = $this->creerBoutique();
        [$autre, $adminAutre] = $this->creerBoutique('Autre', 'autre@test.gn');
        $p = $this->produit($autre, [], 10);
        $this->actingAs($adminAutre)->post('/devis', ['client_nom' => 'Secret', 'lignes' => [['produit_id' => $p->id, 'quantite' => 1]]]);
        $devis = $this->dans($autre, fn () => Devis::latest('id')->first());
        // Notification forgée vers une boutique étrangère : jamais d'accès
        $admin->notify(new CommandeVitrine($devis));
        $id = $admin->notifications()->first()->id;

        $this->actingAs($admin)->get("/notifications/{$id}")->assertRedirect();
        $this->get("/devis/{$devis->id}")->assertNotFound();
    }
}
