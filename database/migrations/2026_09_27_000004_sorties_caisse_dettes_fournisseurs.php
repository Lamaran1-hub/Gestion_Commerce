<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - Dépenses : mode de paiement (une dépense en espèces sort du tiroir-caisse).
 * - Dettes fournisseurs : montant payé et échéance sur chaque réception, règlements fournisseurs.
 * - Clôture de caisse : sorties d'espèces de la journée (dépenses, règlements fournisseurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('depenses', function (Blueprint $table) {
            $table->string('mode', 30)->default('especes')->after('montant');
        });

        Schema::table('approvisionnements', function (Blueprint $table) {
            $table->unsignedBigInteger('montant_paye')->default(0)->after('total');
            $table->date('echeance')->nullable()->after('montant_paye');
        });
        Schema::create('paiements_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->foreignId('approvisionnement_id')->nullable()->constrained('approvisionnements')->cascadeOnDelete();
            $table->unsignedBigInteger('montant');
            $table->string('mode', 30);
            $table->string('reference')->nullable();
            $table->dateTime('date_paiement');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['boutique_id', 'date_paiement']);
        });

        Schema::table('clotures_caisse', function (Blueprint $table) {
            $table->bigInteger('sorties_especes')->default(0)->after('encaissements');
        });
    }

    public function down(): void
    {
        Schema::table('clotures_caisse', fn (Blueprint $t) => $t->dropColumn('sorties_especes'));
        Schema::dropIfExists('paiements_fournisseur');
        Schema::table('approvisionnements', fn (Blueprint $t) => $t->dropColumn(['montant_paye', 'echeance']));
        Schema::table('depenses', fn (Blueprint $t) => $t->dropColumn('mode'));
    }
};
