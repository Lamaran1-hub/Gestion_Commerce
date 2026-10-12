<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fond de caisse : la monnaie mise dans le tiroir le matin. Elle s'ajoute aux espèces attendues à la clôture,
 * et la clôture note ce qui est laissé dans le tiroir pour le lendemain (proposé comme fond suivant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fonds_caisse', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();   // le caissier dont c'est le tiroir
            $t->date('jour');
            $t->unsignedBigInteger('montant');
            $t->timestamps();
            $t->unique(['user_id', 'jour']);
        });
        Schema::table('clotures_caisse', function (Blueprint $t) {
            $t->unsignedBigInteger('fond_caisse')->default(0)->after('sorties_especes');
            $t->unsignedBigInteger('laisse_en_caisse')->nullable()->after('especes_comptees');
        });
    }

    public function down(): void
    {
        Schema::table('clotures_caisse', fn (Blueprint $t) => $t->dropColumn(['fond_caisse', 'laisse_en_caisse']));
        Schema::dropIfExists('fonds_caisse');
    }
};
