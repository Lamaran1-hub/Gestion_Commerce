<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Trésorerie par compte (caisse, Orange Money, MTN, banque…) :
 * transferts entre comptes (avec frais), apports et retraits de l'exploitant, constats de solde.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operations_tresorerie', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->string('type', 20);                       // transfert, apport, retrait, constat
            $t->string('compte_source', 30)->nullable();
            $t->string('compte_destination', 30)->nullable();
            $t->unsignedBigInteger('montant');
            $t->unsignedBigInteger('frais')->default(0);  // frais de retrait / transfert, payés par le compte source
            $t->bigInteger('ecart')->nullable();          // constat : solde réel − solde calculé
            $t->string('motif')->nullable();
            $t->string('reference', 100)->nullable();
            $t->dateTime('date_operation');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['boutique_id', 'date_operation']);
        });

        // Les gestionnaires existants reçoivent le nouveau droit, comme les nouvelles boutiques
        foreach (DB::table('roles')->where('nom', 'Gestionnaire')->get(['id', 'permissions']) as $r) {
            $permissions = json_decode($r->permissions ?? '[]', true) ?: [];
            if (! in_array('tresorerie.gerer', $permissions, true)) {
                $permissions[] = 'tresorerie.gerer';
                DB::table('roles')->where('id', $r->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operations_tresorerie');
    }
};
