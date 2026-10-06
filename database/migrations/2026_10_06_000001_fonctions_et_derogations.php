<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Formules : fonctions incluses (null = toutes) ; boutiques : dérogations accordées par le propriétaire
 * (limites différentes de la formule, fonctions en plus).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $t) {
            $t->json('fonctions')->nullable()->after('max_boutiques');
        });
        Schema::table('boutiques', function (Blueprint $t) {
            $t->json('derogations')->nullable();
        });
        // Formules standard : l'essentiel pour Démarrage, presque tout pour Commerce, tout pour Entreprise (null)
        DB::table('plans')->where('nom', 'Démarrage')->update(['fonctions' => json_encode(['hors_ligne', 'relances', 'etiquettes'])]);
        DB::table('plans')->where('nom', 'Commerce')->update(['fonctions' => json_encode(['hors_ligne', 'relances', 'etiquettes',
            'peremptions', 'promotions', 'fidelite', 'tresorerie', 'import_catalogue'])]);
    }

    public function down(): void
    {
        Schema::table('plans', fn (Blueprint $t) => $t->dropColumn('fonctions'));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn('derogations'));
    }
};
