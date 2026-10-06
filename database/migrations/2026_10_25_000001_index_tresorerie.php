<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Index pour la trésorerie et la clôture de caisse : les soldes filtrent les paiements et les dépenses
 * par moyen de paiement (espèces, Orange Money…) et par date ; la clôture filtre les ventes d'un caissier par jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', fn (Blueprint $t) => $t->index(['boutique_id', 'mode', 'date_paiement'], 'paiements_boutique_mode_date'));
        Schema::table('depenses', fn (Blueprint $t) => $t->index(['boutique_id', 'mode', 'created_at'], 'depenses_boutique_mode_date'));
        Schema::table('ventes', fn (Blueprint $t) => $t->index(['boutique_id', 'user_id', 'date_vente'], 'ventes_boutique_caissier_date'));
    }

    public function down(): void
    {
        Schema::table('paiements', fn (Blueprint $t) => $t->dropIndex('paiements_boutique_mode_date'));
        Schema::table('depenses', fn (Blueprint $t) => $t->dropIndex('depenses_boutique_mode_date'));
        Schema::table('ventes', fn (Blueprint $t) => $t->dropIndex('ventes_boutique_caissier_date'));
    }
};
