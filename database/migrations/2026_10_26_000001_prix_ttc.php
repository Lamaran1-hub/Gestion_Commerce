<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prix de vente TVA comprise : réglage de la boutique, et mode mémorisé sur chaque vente et devis
 * (changer le réglage ne modifie jamais les ventes, retours et factures déjà faits).
 * Existant : prix hors taxe (la TVA s'ajoute), comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->boolean('prix_ttc')->default(false)->after('tva_taux'));
        Schema::table('ventes', fn (Blueprint $t) => $t->boolean('prix_ttc')->default(false)->after('total_ttc'));
        Schema::table('devis', fn (Blueprint $t) => $t->boolean('prix_ttc')->default(false)->after('total_ttc'));
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('prix_ttc'));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('prix_ttc'));
        Schema::table('devis', fn (Blueprint $t) => $t->dropColumn('prix_ttc'));
    }
};
