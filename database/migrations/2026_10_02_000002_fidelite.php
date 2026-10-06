<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Programme de fidélité : un pourcentage des sommes payées devient des points (1 point = 1 GNF),
 * utilisables à la caisse. Chaque gain ou utilisation est tracé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boutiques', function (Blueprint $t) {
            $t->decimal('fidelite_taux', 5, 2)->default(0)->after('alerte_peremption_jours');   // 0 = programme désactivé
            $t->unsignedInteger('fidelite_minimum')->default(0)->after('fidelite_taux');           // points minimum pour les utiliser
        });
        Schema::table('clients', function (Blueprint $t) {
            $t->bigInteger('points')->default(0);
        });
        Schema::create('points_fidelite', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->bigInteger('points');                      // + gagnés, − utilisés ou repris
            $t->string('motif');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['boutique_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('points_fidelite');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('points'));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['fidelite_taux', 'fidelite_minimum']));
    }
};
