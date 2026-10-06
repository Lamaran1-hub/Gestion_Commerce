<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Commandes fournisseurs suivies : envoyée → reçue en partie → reçue (ou soldée / annulée).
 * Les réceptions se rattachent à la commande et mettent à jour les quantités reçues ;
 * « À commander » ne propose plus ce qui est déjà en route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commandes_fournisseur', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->string('numero', 30);
            $t->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $t->date('date_commande');
            $t->date('livraison_prevue_le')->nullable();
            $t->string('statut', 20)->default('envoyee');   // envoyee, partielle, recue, soldee, annulee
            $t->unsignedBigInteger('total_estime')->default(0);
            $t->text('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['boutique_id', 'numero']);
            $t->index(['boutique_id', 'statut']);
        });
        Schema::create('lignes_commande_fournisseur', function (Blueprint $t) {
            $t->id();
            $t->foreignId('commande_fournisseur_id')->constrained('commandes_fournisseur')->cascadeOnDelete();
            $t->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->string('designation');
            $t->decimal('quantite', 12, 2);           // en unités de base
            $t->decimal('quantite_recue', 12, 2)->default(0);
            $t->unsignedBigInteger('prix_achat_estime')->default(0);
        });
        Schema::table('approvisionnements', fn (Blueprint $t) => $t->foreignId('commande_fournisseur_id')->nullable()->after('fournisseur_id')
            ->constrained('commandes_fournisseur')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('approvisionnements', fn (Blueprint $t) => $t->dropConstrainedForeignId('commande_fournisseur_id'));
        Schema::dropIfExists('lignes_commande_fournisseur');
        Schema::dropIfExists('commandes_fournisseur');
    }
};
