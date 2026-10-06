<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Un article rapporté garde la trace de son numéro de série (repris par tel retour) ; il redevient vendable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('numeros_serie', fn (Blueprint $t) => $t->foreignId('retour_id')->nullable()->after('produit_id')->constrained('retours')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('numeros_serie', fn (Blueprint $t) => $t->dropConstrainedForeignId('retour_id'));
    }
};
