<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Achat de licence en ligne (Djomy) :
 * - commandes_licence : une tentative d'achat, de la création au paiement vérifié ;
 * - evenements_paiement : webhooks reçus (idempotence, audit) ;
 * - notifications : notifications Laravel (propriétaire et utilisateurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes_licence', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 40)->unique();          // envoyée à Djomy : merchantPaymentReference
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans');
            $table->unsignedSmallInteger('mois');
            $table->unsignedBigInteger('montant');              // calculé par le serveur, jamais par le navigateur
            $table->string('devise', 3)->default('GNF');
            $table->string('fournisseur', 20)->default('djomy');
            // en_attente → payee (licence activée) | a_valider (activation manuelle) | echouee | annulee | expiree | anomalie
            $table->string('statut', 20)->default('en_attente');
            $table->string('numero_payeur', 30)->nullable();
            $table->string('transaction_id', 100)->nullable()->index();
            $table->text('url_paiement')->nullable();
            $table->string('statut_fournisseur', 30)->nullable();
            $table->unsignedBigInteger('montant_recu')->nullable();
            $table->json('reponse_fournisseur')->nullable();
            $table->foreignId('paiement_licence_id')->nullable()->constrained('paiements_licence')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verifiee_le')->nullable();
            $table->timestamp('payee_le')->nullable();
            $table->timestamps();
            $table->index(['statut', 'created_at']);
        });

        Schema::create('evenements_paiement', function (Blueprint $table) {
            $table->id();
            $table->string('fournisseur', 20);
            $table->string('evenement_id', 120)->nullable();
            $table->string('type', 60)->nullable();
            $table->string('reference', 120)->nullable();
            $table->boolean('signature_valide')->default(false);
            $table->json('contenu');
            $table->timestamp('traite_le')->nullable();
            $table->timestamps();
            $table->unique(['fournisseur', 'evenement_id']);
        });

        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements_paiement');
        Schema::dropIfExists('commandes_licence');
        Schema::dropIfExists('notifications');
    }
};
