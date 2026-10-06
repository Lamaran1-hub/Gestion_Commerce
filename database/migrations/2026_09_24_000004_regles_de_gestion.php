<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Règles de gestion propres à chaque boutique : remise maximale, vente à perte,
 * crédit client (plafond, délai), délai d'annulation, méthode de calcul du coût d'achat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->decimal('remise_max_pct', 5, 2)->nullable()->default(30)->after('tva_taux');
            $table->boolean('vente_a_perte')->default(false)->after('remise_max_pct');
            $table->unsignedBigInteger('plafond_credit_defaut')->nullable()->after('vente_a_perte');
            $table->unsignedSmallInteger('delai_credit_jours')->nullable()->after('plafond_credit_defaut');
            $table->unsignedSmallInteger('delai_annulation_heures')->nullable()->default(48)->after('delai_credit_jours');
            $table->string('methode_cout', 10)->default('dernier')->after('delai_annulation_heures'); // dernier | cmp
        });
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('plafond_credit')->nullable()->after('ville');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['remise_max_pct', 'vente_a_perte', 'plafond_credit_defaut', 'delai_credit_jours', 'delai_annulation_heures', 'methode_cout']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('plafond_credit'));
    }
};
