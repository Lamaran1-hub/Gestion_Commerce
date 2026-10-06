<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Échéance de chaque vente à crédit : la date à laquelle le client a promis de payer.
 * Fixée à la caisse (par défaut : délai de crédit de la boutique, 30 jours à défaut), reportable ensuite.
 * Les crédits déjà en cours reçoivent l'échéance qu'ils auraient eue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $t) {
            $t->date('echeance')->nullable()->after('montant_paye');
            $t->index(['boutique_id', 'echeance']);
        });

        $delais = DB::table('boutiques')->pluck('delai_credit_jours', 'id');
        DB::table('ventes')->where('statut', 'validee')->whereColumn('montant_paye', '<', 'total_ttc')
            ->orderBy('id')->select('id', 'boutique_id', 'date_vente')
            ->chunkById(500, function ($ventes) use ($delais) {
                foreach ($ventes as $v) {
                    $jours = (int) ($delais[$v->boutique_id] ?? 0) ?: 30;
                    DB::table('ventes')->where('id', $v->id)
                        ->update(['echeance' => \Carbon\Carbon::parse($v->date_vente)->addDays($jours)->toDateString()]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ventes', function (Blueprint $t) {
            $t->dropIndex(['boutique_id', 'echeance']);
            $t->dropColumn('echeance');
        });
    }
};
