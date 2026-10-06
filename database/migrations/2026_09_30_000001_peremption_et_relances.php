<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Dates de péremption saisies à la réception (par ligne), alerte N jours avant.
 * - Date de la dernière relance d'un client débiteur (évite de relancer trop souvent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lignes_approvisionnement', function (Blueprint $t) {
            $t->date('date_peremption')->nullable()->after('total');
        });
        Schema::table('boutiques', function (Blueprint $t) {
            $t->unsignedSmallInteger('alerte_peremption_jours')->default(30)->after('couverture_stock_jours');
        });
        Schema::table('clients', function (Blueprint $t) {
            $t->timestamp('derniere_relance_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lignes_approvisionnement', fn (Blueprint $t) => $t->dropColumn('date_peremption'));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('alerte_peremption_jours'));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('derniere_relance_le'));
    }
};
