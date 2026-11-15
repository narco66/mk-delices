<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('slug', 140)->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->boolean('active')->default(true);
            $table->integer('position')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['active', 'position'], 'idx_categories_active_position');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('slug', 160)->unique();
            $table->string('name', 160);
            $table->string('tagline', 200)->nullable();
            $table->text('description')->nullable();
            $table->text('ingredients')->nullable();
            $table->jsonb('allergens')->default(DB::raw("'[]'::jsonb"));
            $table->text('storage_instructions')->nullable();
            $table->unsignedInteger('shelf_life_hours')->nullable();
            $table->boolean('requires_cold_chain')->default(false);
            $table->string('status', 20)->default('draft');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_new')->default(false);
            $table->boolean('is_signature')->default(false);
            $table->integer('position')->default(0);
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestampTz('published_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['status', 'category_id'], 'idx_products_status_category');
        });
        DB::statement("ALTER TABLE products ADD CONSTRAINT chk_products_status CHECK (status IN ('draft','published','inactive','archived'))");

        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->string('sku', 60)->unique();
            $table->string('label', 120);
            $table->string('flavor', 80)->nullable();
            $table->string('size_label', 60)->nullable();
            $table->unsignedSmallInteger('serves')->nullable();
            $table->bigInteger('price_xof');
            $table->bigInteger('compare_at_price_xof')->nullable();
            $table->boolean('available')->default(true);
            $table->unsignedInteger('prep_minutes')->default(0);
            $table->integer('stock_qty')->nullable();
            $table->unsignedSmallInteger('max_per_order')->nullable();
            $table->integer('position')->default(0);
            $table->unsignedInteger('version')->default(1);
            $table->timestampsTz();
            $table->softDeletesTz();
        });
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_variants_price CHECK (price_xof >= 0)');
        DB::statement('ALTER TABLE product_variants ADD CONSTRAINT chk_variants_stock CHECK (stock_qty IS NULL OR stock_qty >= 0)');

        Schema::create('product_images', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->jsonb('variants')->nullable();
            $table->string('alt', 200);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_illustrative')->default(false);
            $table->timestampsTz();
        });

        Schema::create('product_relations', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignUuid('related_product_id')->constrained('products')->cascadeOnDelete();
            $table->primary(['product_id', 'related_product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_relations');
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
