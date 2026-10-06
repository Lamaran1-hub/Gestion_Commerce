<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Coût d'achat unitaire mémorisé sur chaque mouvement de stock : les pertes (casse, vol,
 * péremption, écarts d'inventaire) sont chiffrées au coût du moment, pas au coût d'aujourd'hui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mouvements_stock', function (Blueprint $t) {
            $t->unsignedBigInteger('cout_unitaire')->nullable()->after('stock_apres');
        });
        // Historique : à défaut de mieux, le coût actuel du produit
        DB::table('mouvements_stock')->whereNull('cout_unitaire')->update([
            'cout_unitaire' => DB::raw('(SELECT prix_achat FROM produits WHERE produits.id = mouvements_stock.produit_id)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('mouvements_stock', fn (Blueprint $t) => $t->dropColumn('cout_unitaire'));
    }
};
