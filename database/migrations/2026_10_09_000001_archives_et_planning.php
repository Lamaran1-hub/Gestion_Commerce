<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Archives fiscales : un fichier signé par mois (ventes, paiements, retours, clôtures, registre),
 *   conservé 6 ans minimum, dont l'empreinte permet de prouver qu'il n'a pas été modifié.
 * - Planning des équipes : horaires prévus par employé et par jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archives_fiscales', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->date('periode_du');
            $t->date('periode_au');
            $t->string('fichier');
            $t->char('empreinte', 64);                        // SHA-256 du fichier
            $t->char('empreinte_registre', 64)->nullable();   // dernière empreinte du registre à la date d'archivage
            $t->unsignedInteger('nb_ventes')->default(0);
            $t->unsignedBigInteger('total_ttc')->default(0);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['boutique_id', 'periode_du']);
        });
        Schema::create('plannings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->date('jour');
            $t->time('debut');
            $t->time('fin');
            $t->timestamps();
            $t->unique(['user_id', 'jour']);
            $t->index(['boutique_id', 'jour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plannings');
        Schema::dropIfExists('archives_fiscales');
    }
};
