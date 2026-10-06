<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prix de gros : un produit peut avoir un prix de gros, appliqué automatiquement
 * à partir d'une quantité minimale, ou toujours pour un client grossiste (revendeur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->unsignedBigInteger('prix_gros')->nullable()->after('prix_vente');
            $table->decimal('quantite_gros', 12, 2)->nullable()->after('prix_gros');
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->boolean('grossiste')->default(false)->after('plafond_credit');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('grossiste'));
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['prix_gros', 'quantite_gros']));
    }
};
