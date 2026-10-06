<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Devis / factures proforma : prix garantis jusqu'à la date de validité, sans mouvement de stock ;
 * transformés en vente quand le client revient payer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 30);
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('client_nom')->nullable();          // prospect sans fiche client
            $table->date('date_devis');
            $table->date('valable_jusqu_au');
            $table->unsignedBigInteger('total_ht')->default(0);
            $table->unsignedBigInteger('remise')->default(0);
            $table->decimal('tva_taux', 5, 2)->default(0);
            $table->unsignedBigInteger('total_tva')->default(0);
            $table->unsignedBigInteger('total_ttc')->default(0);
            $table->string('statut', 20)->default('en_cours');  // en_cours, converti, annule
            $table->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['boutique_id', 'numero']);
        });
        Schema::create('lignes_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('designation');
            $table->decimal('quantite', 12, 2);
            $table->unsignedBigInteger('prix_unitaire');
            $table->unsignedBigInteger('total');
        });
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unsignedSmallInteger('validite_devis_jours')->default(15)->after('delai_retour_jours');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('validite_devis_jours'));
        Schema::dropIfExists('lignes_devis');
        Schema::dropIfExists('devis');
    }
};
