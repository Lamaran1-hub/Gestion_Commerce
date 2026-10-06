<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Garantie et numéros de série (téléphones, électroménager, outillage…).
 * La durée de garantie est figée sur la ligne de vente au moment de la vente ;
 * les numéros de série (IMEI…) sont notés sur la vente et imprimés sur le reçu et la facture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $t) {
            $t->unsignedSmallInteger('garantie_mois')->nullable();
            $t->boolean('suivi_serie')->default(false);
        });
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->unsignedSmallInteger('garantie_mois')->nullable());
        Schema::create('numeros_serie', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $t->foreignId('ligne_vente_id')->constrained('lignes_vente')->cascadeOnDelete();
            $t->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->string('numero', 60);
            $t->timestamps();
            $t->index(['boutique_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('numeros_serie');
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn('garantie_mois'));
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['garantie_mois', 'suivi_serie']));
    }
};
