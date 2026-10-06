<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des prix des produits : chaque changement de prix de vente, d'achat, de gros ou par conditionnement
 * est noté (ancien, nouveau, origine, auteur). Traçabilité et contrôle des baisses de prix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historique_prix', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $t->string('champ', 30);              // prix_vente, prix_achat, prix_gros, prix_conditionnement
            $t->bigInteger('ancien')->nullable();
            $t->bigInteger('nouveau')->nullable();
            $t->string('origine', 60);            // Fiche produit, Réception AP-…, Import Excel, Transfert, Passage TVA…
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['boutique_id', 'produit_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historique_prix');
    }
};
