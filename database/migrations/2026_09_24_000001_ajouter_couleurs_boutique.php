<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Jusqu'à trois couleurs d'entreprise : principale (existante), secondaire et accent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('couleur_2', 7)->nullable()->after('couleur');
            $table->string('couleur_3', 7)->nullable()->after('couleur_2');
        });
    }

    public function down(): void
    {
        Schema::table('boutiques', function (Blueprint $table) {
            $table->dropColumn(['couleur_2', 'couleur_3']);
        });
    }
};
