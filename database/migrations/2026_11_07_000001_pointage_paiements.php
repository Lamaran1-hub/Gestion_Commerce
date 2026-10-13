<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pointage des paiements électroniques (mobile money, carte, virement, chèque) : le responsable coche
 * ceux qu'il retrouve sur ses SMS ou son relevé ; ceux qu'il ne retrouve pas sont signalés en litige.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $t) {
            $t->timestamp('pointe_le')->nullable();
            $t->foreignId('pointe_par')->nullable()->constrained('users')->nullOnDelete();
            $t->string('litige', 200)->nullable();   // « introuvable sur le relevé », avec la note du responsable
            $t->index(['boutique_id', 'mode', 'pointe_le']);
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $t) {
            $t->dropIndex(['boutique_id', 'mode', 'pointe_le']);
            $t->dropConstrainedForeignId('pointe_par');
            $t->dropColumn(['pointe_le', 'litige']);
        });
    }
};
