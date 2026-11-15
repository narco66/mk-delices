<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->string('group', 40)->index();
            $table->jsonb('value')->nullable();
            $table->boolean('is_public')->default(false);
            $table->boolean('requires_validation')->default(false);
            $table->string('description')->nullable();
            $table->timestampsTz();
        });

        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->text('districts')->nullable();
            $table->boolean('active')->default(true);
            $table->bigInteger('delivery_fee_xof');
            $table->bigInteger('min_order_xof')->nullable();
            $table->bigInteger('partner_fee_xof')->default(0);
            $table->unsignedInteger('extra_lead_minutes')->default(0);
            $table->integer('position')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();
        });
        DB::statement('ALTER TABLE delivery_zones ADD CONSTRAINT chk_zones_fees CHECK (delivery_fee_xof >= 0 AND partner_fee_xof >= 0 AND (min_order_xof IS NULL OR min_order_xof >= 0))');

        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->string('first_name', 80);
            $table->string('last_name', 80)->nullable();
            $table->string('phone', 30)->index();
            $table->string('email')->nullable();
            $table->timestampTz('marketing_consent_at')->nullable();
            $table->jsonb('preferences')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();
        });

        Schema::create('addresses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('zone_id')->constrained('delivery_zones')->restrictOnDelete();
            $table->string('label', 60)->nullable();
            $table->string('district', 120);
            $table->string('street', 200)->nullable();
            $table->string('landmark', 255)->nullable();
            $table->decimal('lat', 9, 6)->nullable();
            $table->decimal('lng', 9, 6)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('delivery_zones');
        Schema::dropIfExists('settings');
    }
};
