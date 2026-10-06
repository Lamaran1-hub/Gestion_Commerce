<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Réapprovisionnement : nombre de jours de ventes que le stock doit couvrir après une commande.
 * - Verrouillage de période : rien ne peut plus être modifié jusqu'à cette date (mois clôturés).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->unsignedSmallInteger('couverture_stock_jours')->default(14)->after('validite_devis_jours');
            $table->date('periode_verrouillee_jusquau')->nullable()->after('couverture_stock_jours');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['couverture_stock_jours', 'periode_verrouillee_jusquau']));
    }
};
