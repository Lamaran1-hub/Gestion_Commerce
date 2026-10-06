<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plusieurs points de vente pour un même commerçant (réseau) et transferts de stock entre eux.
 * Chaque boutique garde son catalogue, son stock, sa caisse et sa licence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entreprises', function (Blueprint $t) {
            $t->id();
            $t->string('nom', 150);
            $t->timestamps();
        });
        Schema::table('boutiques', function (Blueprint $t) {
            $t->foreignId('entreprise_id')->nullable()->after('id')->constrained('entreprises')->nullOnDelete();
        });

        // Transfert : envoyé par une boutique (stock sorti), puis reçu par l'autre (quantités réellement arrivées)
        Schema::create('transferts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('entreprise_id')->constrained('entreprises')->cascadeOnDelete();
            $t->string('numero', 30);
            $t->foreignId('boutique_source_id')->constrained('boutiques')->cascadeOnDelete();
            $t->foreignId('boutique_destination_id')->constrained('boutiques')->cascadeOnDelete();
            $t->string('statut', 20)->default('envoye');   // envoye, recu, annule
            $t->unsignedBigInteger('valeur')->default(0);   // au coût d'achat
            $t->string('note', 500)->nullable();
            $t->foreignId('envoye_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('envoye_le')->nullable();
            $t->foreignId('recu_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('recu_le')->nullable();
            $t->string('note_reception', 500)->nullable();
            $t->timestamps();
            $t->unique(['boutique_source_id', 'numero']);
            $t->index(['boutique_destination_id', 'statut']);
        });
        Schema::create('lignes_transfert', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transfert_id')->constrained('transferts')->cascadeOnDelete();
            $t->foreignId('produit_source_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->foreignId('produit_destination_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->string('designation');
            $t->string('code_barre', 60)->nullable();
            $t->string('unite', 30)->nullable();
            $t->decimal('quantite', 12, 2);                   // envoyée (unités de base)
            $t->decimal('quantite_recue', 12, 2)->nullable();
            $t->unsignedBigInteger('cout_unitaire')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_transfert');
        Schema::dropIfExists('transferts');
        Schema::table('boutiques', function (Blueprint $t) {
            $t->dropConstrainedForeignId('entreprise_id');
        });
        Schema::dropIfExists('entreprises');
    }
};
