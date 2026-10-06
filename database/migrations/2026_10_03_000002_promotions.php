<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Promotions à durée limitée : sur un produit, une catégorie ou toute la boutique. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->string('nom', 100);
            $t->string('type', 20);                        // pourcentage, prix
            $t->decimal('valeur', 12, 2);                  // % de réduction, ou prix promotionnel à l'unité
            $t->foreignId('produit_id')->nullable()->constrained('produits')->cascadeOnDelete();
            $t->foreignId('categorie_id')->nullable()->constrained('categories')->cascadeOnDelete();
            $t->date('debut');
            $t->date('fin');
            $t->boolean('actif')->default(true);
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['boutique_id', 'debut', 'fin']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
