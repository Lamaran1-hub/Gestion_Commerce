<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avoirs clients : un retour de marchandise peut être « remboursé » en avoir (bon d'achat)
 * que le client dépense plus tard à la caisse. Solde tenu sur la fiche client, chaque mouvement est tracé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->bigInteger('avoir')->default(0));
        Schema::create('avoirs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->foreignId('vente_id')->nullable()->constrained('ventes')->nullOnDelete();
            $t->foreignId('retour_id')->nullable()->constrained('retours')->nullOnDelete();
            $t->bigInteger('montant');                     // + crédité (retour), − utilisé en paiement
            $t->string('motif');
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['boutique_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avoirs');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn('avoir'));
    }
};
