<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kits (packs composés) : un produit vendu comme un tout (« Pack rentrée », « Kit cuisine »…) dont le stock
 * est celui de ses composants. Vendre un kit sort ses composants du stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $t) {
            $t->boolean('est_kit')->default(false)->after('suivi_serie');
        });
        Schema::create('composants_kit', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('kit_id')->constrained('produits')->cascadeOnDelete();
            $t->foreignId('composant_id')->constrained('produits')->cascadeOnDelete();
            $t->decimal('quantite', 10, 2);
            $t->timestamps();
            $t->unique(['kit_id', 'composant_id']);
            $t->index('composant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('composants_kit');
        Schema::table('produits', function (Blueprint $t) {
            $t->dropColumn('est_kit');
        });
    }
};
