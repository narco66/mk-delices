<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_partners', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('display_name', 120);
            $table->string('phone', 30);
            $table->string('transport_mode', 30)->default('moto');
            $table->boolean('active')->default(true);
            $table->boolean('available')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();
        });

        Schema::create('delivery_partner_zones', function (Blueprint $table) {
            $table->foreignUuid('partner_id')->constrained('delivery_partners')->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('delivery_zones')->cascadeOnDelete();
            $table->primary(['partner_id', 'zone_id']);
        });

        // Arbitrage DEV-ARCH-004 : mission (affectation) et exécution (deliveries) fusionnées, relation 1:1.
        Schema::create('delivery_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('partner_id')->constrained('delivery_partners')->restrictOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('proposed');
            $table->bigInteger('partner_fee_xof')->default(0);
            $table->bigInteger('cash_to_collect_xof')->default(0);
            $table->bigInteger('cash_collected_xof')->nullable();
            $table->timestampTz('assigned_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('refused_at')->nullable();
            $table->text('refusal_reason')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->boolean('packaging_checked')->default(false);
            $table->timestampTz('delivered_at')->nullable();
            $table->string('proof_type', 40)->nullable();
            $table->string('proof_reference', 255)->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('incident_reason', 60)->nullable();
            $table->text('incident_note')->nullable();
            $table->string('product_condition', 30)->nullable();
            $table->boolean('contact_attempted')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('settled_at')->nullable();
            $table->foreignUuid('settled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->index(['partner_id', 'status'], 'idx_delivery_assignments_partner_status');
        });
        DB::statement("ALTER TABLE delivery_assignments ADD CONSTRAINT chk_assignments_status CHECK (status IN ('proposed','accepted','refused','picked_up','delivered','failed','cancelled'))");
        DB::statement('ALTER TABLE delivery_assignments ADD CONSTRAINT chk_assignments_fees CHECK (partner_fee_xof >= 0 AND cash_to_collect_xof >= 0)');
        DB::statement("CREATE UNIQUE INDEX idx_delivery_assignments_one_active ON delivery_assignments (order_id) WHERE status IN ('proposed','accepted','picked_up')");
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_assignments');
        Schema::dropIfExists('delivery_partner_zones');
        Schema::dropIfExists('delivery_partners');
    }
};
