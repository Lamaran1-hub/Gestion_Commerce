<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registre inaltérable des opérations de caisse (principe anti-fraude : inaltérabilité, sécurisation,
 * conservation). Chaque ligne est chaînée à la précédente par une empreinte SHA-256 : toute modification
 * ou suppression ultérieure, même directement dans la base, est détectée au contrôle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registre_caisse', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('sequence');              // numéro d'ordre continu par boutique
            $t->string('type', 20);                          // vente, paiement, retour, annulation, cloture
            $t->unsignedBigInteger('reference_id');
            $t->longText('donnees');                         // texte exact signé (pas de type JSON : l'ordre des clés doit rester identique)
            $t->char('empreinte_precedente', 64);
            $t->char('empreinte', 64);
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['boutique_id', 'sequence']);
            $t->index(['boutique_id', 'type', 'reference_id']);
        });
        Schema::table('ventes', function (Blueprint $t) {
            $t->char('empreinte', 64)->nullable();
        });
        Schema::table('clotures_caisse', function (Blueprint $t) {
            $t->char('empreinte', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registre_caisse');
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn('empreinte'));
        Schema::table('clotures_caisse', fn (Blueprint $t) => $t->dropColumn('empreinte'));
    }
};
