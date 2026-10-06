<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Objectifs de vente mensuels (boutique et vendeurs) et commission des vendeurs (% du chiffre d'affaires hors taxes).
 * Vide = pas d'objectif / pas de commission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->unsignedBigInteger('objectif_mensuel')->nullable());
        Schema::table('users', function (Blueprint $t) {
            $t->unsignedBigInteger('objectif_mensuel')->nullable();
            $t->decimal('commission_pct', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('objectif_mensuel'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['objectif_mensuel', 'commission_pct']));
    }
};
