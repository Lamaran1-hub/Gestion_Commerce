<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tickets mis en attente à la caisse (client parti chercher l'argent, commande en cours de préparation). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ventes_en_attente', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $t->string('libelle', 100)->nullable();
            $t->json('lignes');
            $t->unsignedBigInteger('total')->default(0);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ventes_en_attente');
    }
};
