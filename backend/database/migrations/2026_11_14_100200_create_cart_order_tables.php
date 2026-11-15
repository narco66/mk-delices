<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 40)->unique();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->string('discount_type', 10);
            $table->bigInteger('discount_value');
            $table->bigInteger('min_subtotal_xof')->nullable();
            $table->bigInteger('max_discount_xof')->nullable();
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('active')->default(true);
            $table->boolean('is_public')->default(false);
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT chk_promotions_type CHECK (discount_type IN ('percent','fixed'))");
        DB::statement("ALTER TABLE promotions ADD CONSTRAINT chk_promotions_value CHECK (discount_value > 0 AND (discount_type <> 'percent' OR discount_value <= 100))");

        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('guest_token_hash', 64);
            $table->string('promotion_code', 40)->nullable();
            $table->uuid('converted_order_id')->nullable()->unique();
            $table->timestampTz('expires_at');
            $table->timestampsTz();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestampsTz();
            $table->unique(['cart_id', 'variant_id']);
        });
        DB::statement('ALTER TABLE cart_items ADD CONSTRAINT chk_cart_items_qty CHECK (quantity > 0)');

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 20)->unique();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->restrictOnDelete();
            $table->foreignUuid('cart_id')->nullable()->unique()->constrained('carts')->nullOnDelete();
            $table->string('channel', 20)->default('web');
            $table->string('status', 30)->default('pending_confirmation');
            $table->string('payment_status', 30)->default('unpaid');
            $table->string('payment_method', 30)->nullable();
            $table->string('fulfillment_type', 20)->default('delivery');
            $table->bigInteger('subtotal_xof');
            $table->bigInteger('discount_xof')->default(0);
            $table->bigInteger('delivery_fee_xof')->default(0);
            $table->bigInteger('total_xof');
            $table->bigInteger('paid_xof')->default(0);
            $table->bigInteger('refunded_xof')->default(0);
            $table->char('currency', 3)->default('XOF');
            $table->foreignUuid('promotion_id')->nullable()->constrained('promotions')->restrictOnDelete();
            $table->string('promotion_code', 40)->nullable();
            $table->foreignUuid('zone_id')->nullable()->constrained('delivery_zones')->restrictOnDelete();
            $table->jsonb('customer_snapshot');
            $table->jsonb('address_snapshot')->nullable();
            $table->timestampTz('slot_start_at')->nullable();
            $table->timestampTz('slot_end_at')->nullable();
            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->string('tracking_token_hash', 64)->nullable();
            $table->timestampTz('tracking_token_expires_at')->nullable();
            $table->timestampTz('terms_accepted_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_test')->default(false);
            $table->boolean('details_locked')->default(false);
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('preparing_at')->nullable();
            $table->timestampTz('ready_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampsTz();
            $table->index(['created_at', 'status'], 'idx_orders_created_at_status');
            $table->index(['status', 'slot_start_at'], 'idx_orders_status_slot');
            $table->index('payment_status', 'idx_orders_payment_status');
        });
        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_status CHECK (status IN ('draft','pending_confirmation','accepted','preparing','ready','assigned','picked_up','delivered','delivery_failed','cancelled','rejected'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_payment_status CHECK (payment_status IN ('unpaid','pending','paid','failed','partially_refunded','refunded'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_channel CHECK (channel IN ('web','whatsapp','phone','backoffice'))");
        DB::statement("ALTER TABLE orders ADD CONSTRAINT chk_orders_fulfillment CHECK (fulfillment_type IN ('delivery','pickup'))");
        DB::statement('ALTER TABLE orders ADD CONSTRAINT chk_orders_amounts CHECK (subtotal_xof >= 0 AND discount_xof >= 0 AND delivery_fee_xof >= 0 AND discount_xof <= subtotal_xof AND total_xof >= 0 AND total_xof = subtotal_xof - discount_xof + delivery_fee_xof AND paid_xof >= 0 AND refunded_xof >= 0 AND refunded_xof <= paid_xof)');

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('variant_id')->constrained('product_variants')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name_snapshot', 160);
            $table->string('variant_label_snapshot', 120);
            $table->string('sku_snapshot', 60);
            $table->bigInteger('unit_price_xof');
            $table->unsignedInteger('qty');
            $table->bigInteger('line_total_xof');
            $table->jsonb('allergens_snapshot');
            $table->text('storage_snapshot')->nullable();
            $table->unsignedInteger('prep_minutes_snapshot')->default(0);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_qty CHECK (qty > 0)');
        DB::statement('ALTER TABLE order_items ADD CONSTRAINT chk_order_items_total CHECK (unit_price_xof >= 0 AND line_total_xof = unit_price_xof * qty)');

        Schema::create('order_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label', 80)->nullable();
            $table->string('type', 30);
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30)->nullable();
            $table->text('reason')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('occurred_at');
            $table->index(['order_id', 'occurred_at'], 'idx_order_events_order_occurred');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('promotions');
    }
};
