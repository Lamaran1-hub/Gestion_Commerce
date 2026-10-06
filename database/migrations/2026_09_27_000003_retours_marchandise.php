<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retours partiels de marchandise (avoirs).
 * Principe « net » : la vente et ses lignes portent les montants et quantités après retours
 * (tous les calculs existants restent justes) ; chaque retour est tracé dans retours / lignes_retour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $table) {
            $table->unsignedBigInteger('montant_retourne')->default(0)->after('montant_paye');
        });
        Schema::table('lignes_vente', function (Blueprint $table) {
            $table->decimal('quantite_retournee', 12, 2)->default(0)->after('quantite');
        });
        // Un remboursement est un paiement négatif : il se déduit automatiquement de la caisse et des encaissements
        Schema::table('paiements', function (Blueprint $table) {
            $table->bigInteger('montant')->change();
        });
        // Type de mouvement : texte contrôlé par l'application (nouveau type « retour_client »)
        Schema::table('mouvements_stock', function (Blueprint $table) {
            $table->string('type', 30)->change();
        });
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unsignedSmallInteger('delai_retour_jours')->nullable()->default(7)->after('delai_annulation_heures');
        });

        Schema::create('retours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 30);
            $table->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $table->unsignedBigInteger('montant');          // valeur TTC de la marchandise retournée
            $table->unsignedBigInteger('rembourse')->default(0);
            $table->string('mode_remboursement', 30)->nullable();
            $table->string('motif');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['boutique_id', 'numero']);
        });
        Schema::create('lignes_retour', function (Blueprint $table) {
            $table->id();
            $table->foreignId('retour_id')->constrained('retours')->cascadeOnDelete();
            $table->foreignId('ligne_vente_id')->constrained('lignes_vente')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('designation');
            $table->decimal('quantite', 12, 2);
            $table->unsignedBigInteger('prix_unitaire');
            $table->unsignedBigInteger('total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_retour');
        Schema::dropIfExists('retours');
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('delai_retour_jours'));
        Schema::table('paiements', fn (Blueprint $t) => $t->unsignedBigInteger('montant')->change());
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn('quantite_retournee'));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('montant_retourne'));
    }
};
