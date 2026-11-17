<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Nature d'un remboursement (audit E2) :
 * - « sale » : réduction de la vente (annulation, geste commercial) → avoir sur la facture ;
 * - « overpayment » : restitution d'un trop-perçu (double encaissement) → aucun avoir, la vente est inchangée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('kind', 20)->default('sale')->after('status');
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_kind CHECK (kind IN ('sale', 'overpayment'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS chk_refunds_kind');
        Schema::table('refunds', fn (Blueprint $table) => $table->dropColumn('kind'));
    }
};
