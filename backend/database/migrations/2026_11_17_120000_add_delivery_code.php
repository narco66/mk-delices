<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Code de remise (décision MK Délices du 17/11/2026) : 4 chiffres par commande, connus du client et du
 * commercial, jamais du livreur, qui doit le demander au destinataire. Les essais erronés sont comptés par
 * mission pour bloquer les essais au hasard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_code', 4)->nullable()->after('reference');
        });
        Schema::table('delivery_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('code_failed_attempts')->default(0);
        });
        // Commandes existantes encore à livrer : un code leur est attribué.
        DB::statement("UPDATE orders SET delivery_code = lpad((floor(random() * 10000))::int::text, 4, '0')
                       WHERE delivery_code IS NULL AND status NOT IN ('delivered', 'cancelled', 'rejected')");
    }

    public function down(): void
    {
        Schema::table('delivery_assignments', fn (Blueprint $table) => $table->dropColumn('code_failed_attempts'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('delivery_code'));
    }
};
