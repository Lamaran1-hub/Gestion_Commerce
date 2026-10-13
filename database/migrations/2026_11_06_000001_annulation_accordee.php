<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Annulation accordée par un responsable : un vendeur sans droit d'annuler (ou hors délai)
 * annule une vente quand un responsable tape son code. L'accord est gardé sur la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $t) {
            $t->foreignId('annulation_accordee_par')->nullable()->after('annulee_par')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', fn (Blueprint $t) => $t->dropConstrainedForeignId('annulation_accordee_par'));
    }
};
