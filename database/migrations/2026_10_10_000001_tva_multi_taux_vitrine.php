<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - TVA multi-taux : taux propre à un produit (null = taux normal de la boutique, 0 = exonéré),
 *   taux réellement appliqué mémorisé sur chaque ligne de vente et de devis.
 * - Vitrine en ligne : catalogue public de la boutique, commandes transmises par WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $t) {
            $t->decimal('taux_tva', 5, 2)->nullable()->after('prix_vente');
            $t->boolean('en_vitrine')->default(true);
        });
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->decimal('taux_tva', 5, 2)->nullable());
        Schema::table('lignes_devis', fn (Blueprint $t) => $t->decimal('taux_tva', 5, 2)->nullable());
        // Historique : le taux de la vente s'appliquait à toutes ses lignes
        DB::statement('UPDATE lignes_vente SET taux_tva = (SELECT tva_taux FROM ventes WHERE ventes.id = lignes_vente.vente_id)');
        DB::statement('UPDATE lignes_devis SET taux_tva = (SELECT tva_taux FROM devis WHERE devis.id = lignes_devis.devis_id)');

        Schema::table('boutiques', function (Blueprint $t) {
            $t->boolean('vitrine_active')->default(false);
            $t->boolean('vitrine_stock_visible')->default(true);
            $t->string('vitrine_message', 300)->nullable();
        });
        Schema::table('devis', function (Blueprint $t) {
            $t->string('origine', 20)->default('caisse');     // caisse, vitrine
            $t->string('client_telephone', 30)->nullable();
        });

        // La vitrine rejoint les formules qui ont déjà la gestion d'équipe (Commerce) ; « toutes les fonctions » l'a d'office
        foreach (DB::table('plans')->whereNotNull('fonctions')->get(['id', 'fonctions']) as $plan) {
            $fonctions = json_decode($plan->fonctions, true) ?: [];
            if (in_array('equipe', $fonctions, true) && ! in_array('vitrine', $fonctions, true)) {
                DB::table('plans')->where('id', $plan->id)->update(['fonctions' => json_encode([...$fonctions, 'vitrine'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('produits', fn (Blueprint $t) => $t->dropColumn(['taux_tva', 'en_vitrine']));
        Schema::table('lignes_vente', fn (Blueprint $t) => $t->dropColumn('taux_tva'));
        Schema::table('lignes_devis', fn (Blueprint $t) => $t->dropColumn('taux_tva'));
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['vitrine_active', 'vitrine_stock_visible', 'vitrine_message']));
        Schema::table('devis', fn (Blueprint $t) => $t->dropColumn(['origine', 'client_telephone']));
    }
};
