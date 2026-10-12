<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Charges fixes (loyer, électricité, salaires, abonnements…) : déclarées une fois, rappelées chaque mois,
 * payées en un clic (le paiement est une dépense ordinaire rattachée à la charge).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charges_fixes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->string('motif', 150);
            $t->string('categorie', 60)->nullable();
            $t->unsignedBigInteger('montant');
            $t->string('mode', 30)->default('especes');
            $t->unsignedTinyInteger('jour');          // jour du mois où elle est due (1 à 28)
            $t->boolean('actif')->default(true);
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
        });
        Schema::table('depenses', function (Blueprint $t) {
            $t->foreignId('charge_fixe_id')->nullable()->after('user_id')->constrained('charges_fixes')->nullOnDelete();
            $t->index(['charge_fixe_id', 'date_depense']);
        });
    }

    public function down(): void
    {
        Schema::table('depenses', function (Blueprint $t) {
            $t->dropIndex(['charge_fixe_id', 'date_depense']);
            $t->dropConstrainedForeignId('charge_fixe_id');
        });
        Schema::dropIfExists('charges_fixes');
    }
};
