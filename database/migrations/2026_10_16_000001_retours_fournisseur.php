<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retours de marchandise au fournisseur (produit défectueux, périmé, erreur de livraison…).
 * La valeur retournée (au prix d'achat de la réception) réduit ce que la boutique doit ;
 * si la réception était déjà payée, le fournisseur rembourse ou accorde un avoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approvisionnements', fn (Blueprint $t) => $t->unsignedBigInteger('montant_retourne')->default(0)->after('montant_paye'));
        Schema::table('lignes_approvisionnement', fn (Blueprint $t) => $t->decimal('quantite_retournee', 12, 2)->default(0)->after('quantite'));
        // Un remboursement du fournisseur est un règlement négatif (il rentre dans la caisse)
        Schema::table('paiements_fournisseur', fn (Blueprint $t) => $t->bigInteger('montant')->change());

        Schema::create('retours_fournisseur', function (Blueprint $t) {
            $t->id();
            $t->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $t->string('numero', 30);
            $t->foreignId('approvisionnement_id')->constrained('approvisionnements')->cascadeOnDelete();
            $t->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $t->unsignedBigInteger('montant');                 // valeur au prix d'achat
            $t->unsignedBigInteger('deduit')->default(0);      // retiré de la dette
            $t->unsignedBigInteger('rembourse')->default(0);   // rendu par le fournisseur (argent ou avoir)
            $t->string('mode_remboursement', 30)->nullable();
            $t->string('motif', 120);
            $t->text('note')->nullable();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['boutique_id', 'numero']);
        });
        Schema::create('lignes_retour_fournisseur', function (Blueprint $t) {
            $t->id();
            $t->foreignId('retour_fournisseur_id')->constrained('retours_fournisseur')->cascadeOnDelete();
            $t->foreignId('ligne_approvisionnement_id')->nullable()->constrained('lignes_approvisionnement')->nullOnDelete();
            $t->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $t->string('designation');
            $t->decimal('quantite', 12, 2);
            $t->decimal('facteur', 12, 2)->default(1);
            $t->string('unite', 30)->nullable();
            $t->unsignedBigInteger('prix_achat_unitaire');
            $t->unsignedBigInteger('total');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_retour_fournisseur');
        Schema::dropIfExists('retours_fournisseur');
        Schema::table('lignes_approvisionnement', fn (Blueprint $t) => $t->dropColumn('quantite_retournee'));
        Schema::table('approvisionnements', fn (Blueprint $t) => $t->dropColumn('montant_retourne'));
    }
};
