<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nombre de points de vente autorisés par formule (vide = illimité).
 * Il se lit sur la formule de la boutique principale du réseau (celle qui a créé les autres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->unsignedInteger('max_boutiques')->nullable()->after('max_produits');
        });
        // Formules standard : 1 boutique pour Démarrage, 3 pour Commerce, illimité pour Entreprise
        DB::table('plans')->where('nom', 'Démarrage')->update(['max_boutiques' => 1]);
        DB::table('plans')->where('nom', 'Commerce')->update(['max_boutiques' => 3]);
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('max_boutiques'));
    }
};
