<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-mails : journal de chaque envoi (réussi ou en échec) et préférence d'abonnement aux nouveautés.
 * Les e-mails liés au compte et à la licence partent toujours ; seules les nouveautés sont facultatives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emails_envoyes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('destinataire', 150);
            $t->string('sujet', 200);
            $t->string('type', 40);              // bienvenue, licence, rappel, nouveaute, test…
            $t->string('statut', 20);            // envoye, echec, ignore
            $t->text('erreur')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['type', 'created_at']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('recevoir_nouveautes')->default(true);
        });
        // Une annonce n'est envoyée par e-mail qu'une seule fois
        Schema::table('annonces', function (Blueprint $t) {
            $t->timestamp('email_envoye_le')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emails_envoyes');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('recevoir_nouveautes'));
        Schema::table('annonces', fn (Blueprint $t) => $t->dropColumn('email_envoye_le'));
    }
};
