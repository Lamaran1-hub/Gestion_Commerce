<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accord du responsable : le gérant tape son code personnel sur la caisse pour autoriser une remise
 * au-delà du maximum (ou accordée par un vendeur qui n'a pas ce droit). L'accord est gardé sur la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('code_accord')->nullable()->after('password');   // haché, comme un mot de passe
        });
        Schema::table('ventes', function (Blueprint $t) {
            $t->foreignId('remise_accordee_par')->nullable()->after('remise')->constrained('users')->nullOnDelete();
        });
        Schema::table('devis', function (Blueprint $t) {
            $t->foreignId('remise_accordee_par')->nullable()->after('remise')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devis', fn (Blueprint $t) => $t->dropConstrainedForeignId('remise_accordee_par'));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropConstrainedForeignId('remise_accordee_par'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('code_accord'));
    }
};
