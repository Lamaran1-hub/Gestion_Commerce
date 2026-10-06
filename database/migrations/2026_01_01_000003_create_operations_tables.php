<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ventes (anciennes « Commandes » WinDev)
        Schema::create('ventes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 30);
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->dateTime('date_vente');
            $table->unsignedBigInteger('total_ht')->default(0);
            $table->unsignedBigInteger('remise')->default(0);
            $table->decimal('tva_taux', 5, 2)->default(0);
            $table->unsignedBigInteger('total_tva')->default(0);
            $table->unsignedBigInteger('total_ttc')->default(0);
            $table->unsignedBigInteger('montant_paye')->default(0);
            $table->enum('statut', ['validee', 'annulee'])->default('validee');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('annulee_le')->nullable();
            $table->foreignId('annulee_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('motif_annulation')->nullable();
            $table->timestamps();
            $table->unique(['boutique_id', 'numero']);
            $table->index(['boutique_id', 'date_vente']);
        });

        Schema::create('lignes_vente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('designation'); // figé au moment de la vente
            $table->decimal('quantite', 12, 2);
            $table->unsignedBigInteger('prix_unitaire');
            $table->unsignedBigInteger('prix_achat')->default(0); // pour calculer la marge
            $table->unsignedBigInteger('total');
        });

        // Encaissements : paiement comptant ou remboursement d'un crédit
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vente_id')->constrained('ventes')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->unsignedBigInteger('montant');
            $table->enum('mode', ['especes', 'orange_money', 'mtn_momo', 'virement', 'cheque', 'carte']);
            $table->string('reference')->nullable();
            $table->dateTime('date_paiement');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['boutique_id', 'date_paiement']);
        });

        // Approvisionnements (réceptions de marchandise)
        Schema::create('approvisionnements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('numero', 30);
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->date('date_appro');
            $table->unsignedBigInteger('total')->default(0);
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['boutique_id', 'numero']);
        });

        Schema::create('lignes_approvisionnement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approvisionnement_id')->constrained('approvisionnements')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('designation');
            $table->decimal('quantite', 12, 2);
            $table->unsignedBigInteger('prix_achat_unitaire');
            $table->unsignedBigInteger('total');
        });

        // Journal des mouvements : le stock n'est jamais modifié sans une ligne ici
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            $table->enum('type', ['approvisionnement', 'vente', 'annulation_vente', 'ajustement', 'stock_initial']);
            $table->decimal('quantite', 12, 2); // positive = entrée, négative = sortie
            $table->decimal('stock_apres', 12, 2);
            $table->nullableMorphs('reference');
            $table->string('motif')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['boutique_id', 'produit_id']);
        });

        Schema::create('depenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('motif');
            $table->string('categorie')->nullable();
            $table->unsignedBigInteger('montant');
            $table->date('date_depense');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['boutique_id', 'date_depense']);
        });

        Schema::create('journal_activites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('description');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['boutique_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['journal_activites', 'depenses', 'mouvements_stock', 'lignes_approvisionnement', 'approvisionnements', 'paiements', 'lignes_vente', 'ventes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
