<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mode de paiement « Autre » (précision saisie dans la référence)
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->enum('mode', ['especes', 'orange_money', 'mtn_momo', 'virement', 'cheque', 'carte', 'autre'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('paiements', function (Blueprint $table) {
            $table->enum('mode', ['especes', 'orange_money', 'mtn_momo', 'virement', 'cheque', 'carte'])->change();
        });
    }
};
