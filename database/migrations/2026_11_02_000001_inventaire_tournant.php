<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inventaire tournant : date du dernier comptage de chaque produit, pour proposer chaque jour
 * quelques produits à compter (jamais comptés, puis les plus anciens, les plus chers d'abord).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $t) {
            $t->timestamp('compte_le')->nullable()->after('stock');
            $t->index(['boutique_id', 'compte_le']);
        });
    }

    public function down(): void
    {
        Schema::table('produits', function (Blueprint $t) {
            $t->dropIndex(['boutique_id', 'compte_le']);
            $t->dropColumn('compte_le');
        });
    }
};
