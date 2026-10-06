<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commissions versées aux vendeurs : une ligne par vendeur et par mois, montant et taux figés au versement.
 * Le versement est aussi enregistré comme dépense (catégorie Salaires) : bénéfice et caisse restent justes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commissions_versees', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();   // le vendeur
            $t->date('mois');                                                      // 1er jour du mois concerné
            $t->unsignedBigInteger('ca_ht');
            $t->decimal('taux', 5, 2);
            $t->unsignedBigInteger('montant');
            $t->string('mode', 30);
            $t->string('reference', 100)->nullable();
            $t->foreignId('depense_id')->nullable()->constrained('depenses')->nullOnDelete();
            $t->foreignId('verse_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['boutique_id', 'user_id', 'mois']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commissions_versees');
    }
};
