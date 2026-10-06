<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->timestamps();
            $table->unique(['boutique_id', 'nom']);
        });

        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->string('contact')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('categorie_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->string('designation');
            $table->string('code_barre')->nullable();
            $table->string('unite', 30)->default('pièce');
            $table->unsignedBigInteger('prix_achat')->default(0); // dernier prix d'achat unitaire (GNF)
            $table->unsignedBigInteger('prix_vente')->default(0);
            $table->decimal('stock', 12, 2)->default(0); // calculé à partir des mouvements
            $table->decimal('seuil_alerte', 12, 2)->default(0);
            $table->string('image')->nullable();
            $table->boolean('actif')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['boutique_id', 'code_barre']); // unicité vérifiée à la validation (produits supprimés exclus)
            $table->index(['boutique_id', 'designation']);
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('nom');
            $table->string('prenom')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['boutique_id', 'code']);
            $table->index(['boutique_id', 'telephone']);
        });

        Schema::create('compteurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->unsignedSmallInteger('annee');
            $table->unsignedInteger('dernier_numero')->default(0);
            $table->unique(['boutique_id', 'type', 'annee']);
        });
    }

    public function down(): void
    {
        foreach (['compteurs', 'clients', 'produits', 'fournisseurs', 'categories'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
