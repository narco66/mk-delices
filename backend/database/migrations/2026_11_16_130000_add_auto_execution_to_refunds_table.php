<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remboursement automatique (audit B1) : marqueur posé AVANT l'appel au prestataire. S'il reste posé sans
 * aboutissement (délai dépassé, erreur réseau), aucune nouvelle tentative automatique n'est faite : l'équipe
 * vérifie chez le prestataire puis confirme manuellement — jamais de double remboursement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->timestampTz('auto_execution_started_at')->nullable()->after('approved_at');
            $table->string('execution_note', 300)->nullable()->after('auto_execution_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn(['auto_execution_started_at', 'execution_note']));
    }
};
