<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anniversaires des clients : date de naissance (facultative), date du dernier vœu envoyé (un par an),
 * et cadeau d'anniversaire choisi par le commerçant (texte ajouté au message, facultatif).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $t) {
            $t->date('date_naissance')->nullable();
            $t->date('dernier_voeu_le')->nullable();
        });
        Schema::table('boutiques', fn (Blueprint $t) => $t->string('cadeau_anniversaire', 200)->nullable());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['date_naissance', 'dernier_voeu_le']));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('cadeau_anniversaire'));
    }
};
