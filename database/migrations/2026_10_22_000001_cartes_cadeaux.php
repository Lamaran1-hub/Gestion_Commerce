<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cartes cadeaux (bons d'achat prépayés) : le client paie aujourd'hui, la personne qui reçoit la carte
 * la dépense plus tard à la caisse, en une ou plusieurs fois. La carte n'est pas nominative : son code fait foi.
 * Chaque mouvement est tracé ; ceux qui portent un moyen de paiement (vente de la carte, remboursement)
 * sont de l'argent réel qui entre ou sort de la caisse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cartes_cadeaux', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->string('code', 20);
            $t->bigInteger('montant');                     // valeur à l'émission
            $t->bigInteger('solde');                       // reste à dépenser
            $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();   // acheteur, s'il a une fiche
            $t->string('acheteur', 120)->nullable();
            $t->string('beneficiaire', 120)->nullable();
            $t->string('telephone', 30)->nullable();       // pour envoyer la carte par WhatsApp
            $t->string('message', 255)->nullable();
            $t->date('expire_le')->nullable();
            $t->string('statut', 20)->default('active');   // active | annulee
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('annulee_le')->nullable();
            $t->string('motif_annulation', 255)->nullable();
            $t->timestamps();
            $t->unique(['boutique_id', 'code']);
        });
        Schema::create('mouvements_carte_cadeau', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('carte_cadeau_id')->constrained('cartes_cadeaux')->cascadeOnDelete();
            $t->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->string('type', 20);                        // emission | utilisation | recredit | remboursement
            $t->bigInteger('montant');                     // + crédité sur la carte, − dépensé ou rendu
            $t->string('mode', 30)->nullable();            // moyen de paiement réel (émission, remboursement)
            $t->string('reference', 120)->nullable();
            $t->string('motif', 255)->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->dateTime('date_mouvement');
            $t->index(['boutique_id', 'date_mouvement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_carte_cadeau');
        Schema::dropIfExists('cartes_cadeaux');
    }
};
