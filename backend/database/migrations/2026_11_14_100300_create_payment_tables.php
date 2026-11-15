<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_reference', 120)->nullable();
            $table->string('status', 20)->default('initiated');
            $table->bigInteger('amount_xof');
            $table->char('currency', 3)->default('XOF');
            $table->string('idempotency_key', 100)->unique();
            $table->text('checkout_url')->nullable();
            $table->string('provider_status', 60)->nullable();
            $table->text('failure_reason')->nullable();
            $table->boolean('is_simulated')->default(false);
            $table->boolean('is_manual')->default(false);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('last_checked_at')->nullable();
            $table->timestampsTz();
            $table->index(['status', 'created_at'], 'idx_payments_status_created');
        });
        DB::statement("ALTER TABLE payments ADD CONSTRAINT chk_payments_status CHECK (status IN ('initiated','pending','succeeded','failed','cancelled','expired'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_amount CHECK (amount_xof > 0)');
        DB::statement('CREATE UNIQUE INDEX idx_payments_provider_reference ON payments (provider, provider_reference) WHERE provider_reference IS NOT NULL');

        Schema::create('payment_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->nullable()->constrained('payments')->restrictOnDelete();
            $table->string('provider', 30);
            $table->string('provider_event_id', 160);
            $table->string('event_type', 80);
            $table->jsonb('payload_redacted');
            $table->string('processing_status', 20)->default('received');
            $table->text('processing_note')->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unique(['provider', 'provider_event_id'], 'idx_payment_events_provider_event');
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->bigInteger('amount_xof');
            $table->string('status', 20)->default('requested');
            $table->text('reason');
            $table->string('provider_ref', 120)->nullable();
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT chk_refunds_status CHECK (status IN ('requested','approved','rejected','succeeded','failed'))");
        DB::statement('ALTER TABLE refunds ADD CONSTRAINT chk_refunds_amount CHECK (amount_xof > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
    }
};
