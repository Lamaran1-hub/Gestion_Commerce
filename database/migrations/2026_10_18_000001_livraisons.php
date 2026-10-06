<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Livraisons : une vente peut être livrée chez le client (à livrer → en route → livrée),
 * avec adresse, contact, date prévue, livreur, et le nom de la personne qui a réceptionné.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ventes', function (Blueprint $t) {
            $t->string('livraison', 20)->nullable()->index();
            $t->string('livraison_adresse')->nullable();
            $t->string('livraison_contact', 120)->nullable();
            $t->date('livraison_prevue_le')->nullable();
            $t->string('livreur', 120)->nullable();
            $t->dateTime('livree_le')->nullable();
            $t->string('livree_a', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ventes', fn (Blueprint $t) => $t->dropColumn(['livraison', 'livraison_adresse', 'livraison_contact', 'livraison_prevue_le', 'livreur', 'livree_le', 'livree_a']));
    }
};
