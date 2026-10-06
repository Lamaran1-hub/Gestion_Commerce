<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reprise de données : les réceptions enregistrées avant le suivi des dettes fournisseurs
 * étaient réglées au moment de la réception. Sans cette reprise, elles apparaîtraient
 * à tort comme des dettes (montant payé à 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('approvisionnements')
            ->where('montant_paye', 0)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('paiements_fournisseur')
                ->whereColumn('paiements_fournisseur.approvisionnement_id', 'approvisionnements.id'))
            ->update(['montant_paye' => DB::raw('total')]);
    }

    public function down(): void
    {
        // Rien à défaire : l'état antérieur (dette inconnue) n'avait pas de sens métier
    }
};
