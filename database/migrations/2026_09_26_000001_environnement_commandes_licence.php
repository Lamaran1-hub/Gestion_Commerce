<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sépare les paiements de test (sandbox) des paiements réels (production)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes_licence', function (Blueprint $table) {
            $table->string('environnement', 12)->default('sandbox')->after('fournisseur');
        });
    }

    public function down(): void
    {
        Schema::table('commandes_licence', fn (Blueprint $t) => $t->dropColumn('environnement'));
    }
};
