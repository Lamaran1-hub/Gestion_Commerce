<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Prix négociés : un client fidèle (revendeur, entreprise) paie certains produits à un prix convenu. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prix_clients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $t->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $t->unsignedBigInteger('prix');
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->timestamps();
            $t->unique(['client_id', 'produit_id']);
            $t->index('produit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prix_clients');
    }
};
