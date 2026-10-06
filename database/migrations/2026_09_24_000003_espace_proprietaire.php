<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Espace Propriétaire (éditeur du logiciel) : fiche client complète, paiements de licence,
 * annonces aux utilisateurs, demandes d'assistance et coordonnées de l'éditeur.
 * + adresse de résidence détaillée des clients des boutiques.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('quartier')->nullable()->after('adresse');
            $table->string('commune')->nullable()->after('quartier');
            $table->string('ville')->nullable()->after('commune');
        });

        // Informations sur le client qui achète le logiciel
        Schema::table('boutiques', function (Blueprint $table) {
            $table->string('responsable_nom')->nullable()->after('nom');
            $table->string('responsable_telephone')->nullable()->after('responsable_nom');
            $table->string('secteur')->nullable()->after('responsable_telephone');
            $table->text('notes_internes')->nullable()->after('pied_facture'); // visibles du propriétaire seulement
        });

        // Chaque paiement de licence reçu, avec la période couverte
        Schema::create('paiements_licence', function (Blueprint $table) {
            $table->id();
            $table->string('numero', 30)->unique();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $table->unsignedBigInteger('montant');
            $table->unsignedSmallInteger('mois')->nullable(); // null = licence sans échéance
            $table->date('periode_du');
            $table->date('periode_au')->nullable();
            $table->string('mode', 30);
            $table->string('reference')->nullable();
            $table->date('paye_le');
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['boutique_id', 'paye_le']);
        });

        // Annonces du propriétaire vers les utilisateurs (nouveautés, maintenance…)
        Schema::create('annonces', function (Blueprint $table) {
            $table->id();
            $table->string('titre');
            $table->text('contenu');
            $table->string('type', 20)->default('nouveaute'); // nouveaute, info, maintenance, important
            $table->foreignId('boutique_id')->nullable()->constrained()->cascadeOnDelete(); // null = toutes les boutiques
            $table->timestamp('publiee_le')->nullable();
            $table->date('expire_le')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('annonce_lectures', function (Blueprint $table) {
            $table->foreignId('annonce_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('lue_le');
            $table->primary(['annonce_id', 'user_id']);
        });

        // Demandes d'assistance : échanges entre les boutiques et le propriétaire
        Schema::create('demandes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boutique_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sujet');
            $table->string('categorie', 30)->default('question');
            $table->string('statut', 20)->default('ouverte'); // ouverte, repondue, fermee
            $table->boolean('lue_proprietaire')->default(false);
            $table->boolean('lue_boutique')->default(true);
            $table->timestamp('dernier_message_le')->nullable();
            $table->timestamps();
        });
        Schema::create('demande_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('demande_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('du_proprietaire')->default(false);
            $table->text('contenu');
            $table->timestamps();
        });

        // Coordonnées et réglages de l'éditeur (clé → valeur)
        Schema::create('parametres_plateforme', function (Blueprint $table) {
            $table->string('cle')->primary();
            $table->text('valeur')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['parametres_plateforme', 'demande_messages', 'demandes', 'annonce_lectures', 'annonces', 'paiements_licence'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('boutiques', fn (Blueprint $t) => $t->dropColumn(['responsable_nom', 'responsable_telephone', 'secteur', 'notes_internes']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['quartier', 'commune', 'ville']));
    }
};
