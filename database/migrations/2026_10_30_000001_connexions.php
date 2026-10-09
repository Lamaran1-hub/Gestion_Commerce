<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des connexions (réussies et échouées) : le titulaire voit qui a ouvert son compte, et il est prévenu
 * d'une connexion depuis un appareil inconnu ou d'essais répétés de mots de passe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connexions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $t->string('email', 190);
            $t->string('ip', 45)->nullable();
            $t->string('appareil', 80);
            $t->boolean('reussie');
            $t->boolean('nouvel_appareil')->default(false);
            $t->char('jeton_appareil', 64)->nullable();   // empreinte du témoin posé sur l'appareil (jamais le témoin lui-même)
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'created_at']);
            $t->index(['user_id', 'jeton_appareil']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connexions');
    }
};
