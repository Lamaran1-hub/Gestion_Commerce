<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espèces en devises (dollar, euro, franc CFA) : la boutique fixe ses taux, la caisse convertit et rend la monnaie
 * en francs guinéens ; la clôture compte les devises à part pour que le tiroir en GNF reste juste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t) {
            $t->json('taux_devises')->nullable();          // {"USD": 8600, "EUR": 9400} : GNF pour 1 unité
        });
        Schema::table('paiements', function (Blueprint $t) {
            $t->string('devise', 3)->nullable()->after('mode');
            $t->decimal('montant_devise', 12, 2)->nullable()->after('devise');   // billets reçus, dans la devise
            $t->decimal('taux_devise', 12, 4)->nullable()->after('montant_devise');
            $t->bigInteger('contre_valeur')->nullable()->after('taux_devise');   // ces billets en GNF au taux du jour
        });
        Schema::table('clotures_caisse', function (Blueprint $t) {
            $t->json('devises')->nullable()->after('billetage');   // {"USD": {"attendu": 100, "compte": 100}}
        });
    }

    public function down(): void
    {
        Schema::table('clotures_caisse', fn (Blueprint $t) => $t->dropColumn('devises'));
        Schema::table('paiements', fn (Blueprint $t) => $t->dropColumn(['devise', 'montant_devise', 'taux_devise', 'contre_valeur']));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('taux_devises'));
    }
};
