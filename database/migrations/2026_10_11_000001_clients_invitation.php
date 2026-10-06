<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relance des clients inactifs (« revenez nous voir ») : date de la dernière invitation envoyée,
 * distincte de la relance pour dette, afin de ne pas solliciter le même client trop souvent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->timestamp('derniere_invitation_le')->nullable());
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('derniere_invitation_le'));
    }
};
