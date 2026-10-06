<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acomptes sur devis et commandes : l'avance versée par le client entre dans la caisse le jour du versement,
 * puis se déduit de la vente quand la commande est livrée. Un remboursement est un acompte négatif.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acomptes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            $t->bigInteger('montant');
            $t->string('mode', 30);
            $t->string('reference', 120)->nullable();
            $t->dateTime('date_versement');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['boutique_id', 'date_versement']);
        });
        Schema::table('devis', fn (Blueprint $t) => $t->unsignedBigInteger('acompte')->default(0)->after('total_ttc'));
    }

    public function down(): void
    {
        Schema::dropIfExists('acomptes');
        Schema::table('devis', fn (Blueprint $t) => $t->dropColumn('acompte'));
    }
};
