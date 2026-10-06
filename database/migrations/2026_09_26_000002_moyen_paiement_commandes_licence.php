<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Moyen choisi par le client (null = choix sur la page Djomy) et moyen réellement utilisé (lu chez Djomy)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_licence', function (Blueprint $table) {
            $table->string('moyen', 20)->nullable()->after('environnement');
            $table->string('moyen_utilise', 20)->nullable()->after('moyen');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_licence', fn (Blueprint $t) => $t->dropColumn(['moyen', 'moyen_utilise']));
    }
};
