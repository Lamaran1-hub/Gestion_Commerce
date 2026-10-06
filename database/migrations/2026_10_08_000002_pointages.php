<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Gestion d'équipe : pointage des arrivées et départs, heures travaillées rapprochées des ventes. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pointages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->dateTime('arrivee');
            $t->dateTime('depart')->nullable();
            $t->string('note', 200)->nullable();             // correction : motif obligatoire
            $t->foreignId('corrige_par')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['boutique_id', 'arrivee']);
            $t->index(['user_id', 'depart']);
        });
        // La formule Commerce inclut la gestion d'équipe (Entreprise inclut tout)
        $commerce = DB::table('plans')->where('nom', 'Commerce')->first();
        if ($commerce && $commerce->fonctions) {
            $fonctions = json_decode($commerce->fonctions, true) ?: [];
            if (! in_array('equipe', $fonctions, true)) {
                $fonctions[] = 'equipe';
                DB::table('plans')->where('id', $commerce->id)->update(['fonctions' => json_encode($fonctions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pointages');
    }
};
