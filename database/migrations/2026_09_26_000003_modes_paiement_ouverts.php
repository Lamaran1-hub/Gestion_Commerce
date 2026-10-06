<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le mode de paiement devient un texte contrôlé par l'application (config gestion.modes_paiement)
 * au lieu d'un ENUM figé : PayCard, Kulu, Soutra Money… s'ajoutent sans nouvelle migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->string('mode', 30)->change();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->enum('mode', ['especes', 'orange_money', 'mtn_momo', 'virement', 'cheque', 'carte', 'autre'])->change();
        });
    }
};
