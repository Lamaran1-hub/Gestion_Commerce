<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Échange d'articles : la valeur rendue par un retour devient un bon d'échange (même pour un client sans fiche),
 * utilisé aussitôt en caisse pour payer les nouveaux articles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('retours', function (Blueprint $t) {
            $t->unsignedBigInteger('echange_restant')->default(0)->after('mode_remboursement');
            $t->foreignId('echange_vente_id')->nullable()->after('echange_restant')->constrained('ventes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('retours', function (Blueprint $t) {
            $t->dropConstrainedForeignId('echange_vente_id');
            $t->dropColumn('echange_restant');
        });
    }
};
