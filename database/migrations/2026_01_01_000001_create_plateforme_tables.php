<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Formules d'abonnement vendues aux boutiques
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->unsignedBigInteger('prix_mensuel')->default(0); // en GNF
            $table->unsignedInteger('max_utilisateurs')->nullable(); // null = illimité
            $table->unsignedInteger('max_produits')->nullable();
            $table->text('description')->nullable();
            $table->boolean('actif')->default(true);
            $table->timestamps();
        });

        // Chaque boutique cliente (locataire de l'application)
        Schema::create('boutiques', function (Blueprint $table) {
            $table->id();
            $table->string('nom');
            $table->string('slug')->unique();
            $table->string('logo')->nullable();
            $table->string('telephone')->nullable();
            $table->string('email')->nullable();
            $table->string('adresse')->nullable();
            $table->string('ville')->nullable();
            $table->string('rccm')->nullable();
            $table->string('nif')->nullable();
            $table->boolean('tva_active')->default(false);
            $table->decimal('tva_taux', 5, 2)->default(18);
            $table->text('pied_facture')->nullable();
            $table->string('couleur', 7)->default('#1F6F54');
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->date('abonnement_expire_le')->nullable();
            $table->enum('statut', ['essai', 'actif', 'suspendu'])->default('essai');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->string('nom');
            $table->json('permissions');
            $table->boolean('systeme')->default(false); // rôle Administrateur non supprimable
            $table->timestamps();
            $table->unique(['boutique_id', 'nom']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->nullable()->constrained()->cascadeOnDelete(); // null = super-admin plateforme
            $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            $table->string('prenom');
            $table->string('nom');
            $table->string('email')->unique();
            $table->string('telephone')->nullable();
            $table->string('password');
            $table->boolean('est_super_admin')->default(false);
            $table->boolean('actif')->default(true);
            $table->boolean('doit_changer_mot_de_passe')->default(false);
            $table->timestamp('derniere_connexion')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration')->index();
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['failed_jobs', 'jobs', 'cache_locks', 'cache', 'sessions', 'password_reset_tokens', 'users', 'roles', 'boutiques', 'plans'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
