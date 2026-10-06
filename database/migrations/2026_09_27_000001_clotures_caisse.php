<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clôture de caisse (rapport Z) : en fin de journée, chaque caissier compte ses espèces ;
 * l'écart avec le montant théorique est enregistré et justifié.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users');             // caissier dont la caisse est clôturée
            $table->date('jour');
            $table->unsignedInteger('nb_ventes')->default(0);
            $table->unsignedBigInteger('total_ventes')->default(0);
            $table->json('encaissements');                                  // mode => montant net (encaissé − remboursé)
            $table->bigInteger('especes_theoriques')->default(0);
            $table->bigInteger('especes_comptees')->default(0);
            $table->bigInteger('ecart')->default(0);                        // comptées − théoriques
            $table->string('motif_ecart')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('cloturee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['boutique_id', 'user_id', 'jour']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse');
    }
};
