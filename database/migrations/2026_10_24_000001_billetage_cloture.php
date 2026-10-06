<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Clôture de caisse : détail du comptage billet par billet (coupure => nombre), imprimé sur le rapport Z. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clotures_caisse', fn (Blueprint $t) => $t->json('billetage')->nullable()->after('especes_comptees'));
    }

    public function down(): void
    {
        Schema::table('clotures_caisse', fn (Blueprint $t) => $t->dropColumn('billetage'));
    }
};
