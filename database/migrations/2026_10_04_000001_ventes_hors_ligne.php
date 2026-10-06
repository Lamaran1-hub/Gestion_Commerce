<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ventes faites sans connexion : identifiant créé sur l'appareil, pour qu'une vente envoyée
 * deux fois (réseau instable) ne soit jamais enregistrée deux fois.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $t) {
            $t->string('uuid_hors_ligne', 36)->nullable()->after('numero');
            $t->timestamp('synchronisee_le')->nullable();
            $t->unique(['boutique_id', 'uuid_hors_ligne']);
        });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $t) {
            $t->dropUnique(['boutique_id', 'uuid_hors_ligne']);
            $t->dropColumn(['uuid_hors_ligne', 'synchronisee_le']);
        });
    }
};
