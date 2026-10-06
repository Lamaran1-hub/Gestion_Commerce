<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vente par conditionnement (carton, casier, sac…).
 * Le stock reste compté dans l'unité de base ; chaque ligne garde sa quantité et son prix
 * dans l'unité vendue, avec un facteur (1 carton = 12 unités) pour les mouvements de stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->string('conditionnement', 30)->nullable()->after('unite');          // ex. « carton »
            $table->decimal('qte_conditionnement', 12, 2)->nullable()->after('conditionnement'); // ex. 12
            $table->unsignedBigInteger('prix_conditionnement')->nullable()->after('quantite_gros');
        });
        foreach (['lignes_vente', 'lignes_devis', 'lignes_retour', 'lignes_approvisionnement'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->decimal('facteur', 12, 2)->default(1)->after('quantite');
                $table->string('unite', 30)->nullable()->after('facteur');
            });
        }
    }

    public function down(): void
    {
        foreach (['lignes_vente', 'lignes_devis', 'lignes_retour', 'lignes_approvisionnement'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn(['facteur', 'unite']));
        }
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['conditionnement', 'qte_conditionnement', 'prix_conditionnement']));
    }
};
