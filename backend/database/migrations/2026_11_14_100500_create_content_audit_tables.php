<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('title', 200);
            $table->string('excerpt', 300)->nullable();
            $table->text('body');
            $table->string('status', 20)->default('draft');
            $table->string('seo_title', 70)->nullable();
            $table->string('seo_description', 170)->nullable();
            $table->boolean('requires_validation')->default(true);
            $table->timestampTz('published_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE content_pages ADD CONSTRAINT chk_content_pages_status CHECK (status IN ('draft','published'))");

        Schema::create('content_blocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 80)->unique();
            $table->string('group', 40)->index();
            $table->jsonb('data');
            $table->string('status', 20)->default('draft');
            $table->integer('position')->default(0);
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE content_blocks ADD CONSTRAINT chk_content_blocks_status CHECK (status IN ('draft','published'))");

        Schema::create('reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('author_name', 80);
            $table->unsignedSmallInteger('stars');
            $table->text('comment')->nullable();
            $table->string('moderation_status', 20)->default('pending');
            $table->foreignUuid('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('moderated_at')->nullable();
            $table->timestampsTz();
            $table->unique(['order_id', 'product_id']);
        });
        DB::statement('ALTER TABLE reviews ADD CONSTRAINT chk_reviews_stars CHECK (stars BETWEEN 1 AND 5)');
        DB::statement("ALTER TABLE reviews ADD CONSTRAINT chk_reviews_moderation CHECK (moderation_status IN ('pending','approved','rejected'))");

        Schema::create('notification_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->uuid('recipient_id')->nullable();
            $table->string('recipient_masked', 60)->nullable();
            $table->string('event', 60);
            $table->string('channel', 20);
            $table->string('dedupe_key', 160)->unique();
            $table->string('delivery_status', 20)->default('queued');
            $table->text('error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->uuidMorphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('target_type', 80)->nullable();
            $table->string('target_id', 64)->nullable();
            $table->jsonb('before_json')->nullable();
            $table->jsonb('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at');
            $table->index(['target_type', 'target_id'], 'idx_audit_logs_target');
            $table->index('created_at', 'idx_audit_logs_created_at');
        });
        // Journal immuable : interdiction UPDATE/DELETE au niveau base.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION mk_forbid_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Journal immuable : % interdit sur %', TG_OP, TG_TABLE_NAME;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER trg_audit_logs_immutable BEFORE UPDATE OR DELETE ON audit_logs FOR EACH ROW EXECUTE FUNCTION mk_forbid_mutation();
            CREATE TRIGGER trg_order_events_immutable BEFORE UPDATE OR DELETE ON order_events FOR EACH ROW EXECUTE FUNCTION mk_forbid_mutation();
        SQL);

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('scope', 60);
            $table->string('key', 100);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_json')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('created_at');
            $table->unique(['scope', 'key'], 'idx_idempotency_scope_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_order_events_immutable ON order_events; DROP TRIGGER IF EXISTS trg_audit_logs_immutable ON audit_logs;');
        Schema::dropIfExists('audit_logs');
        DB::unprepared('DROP FUNCTION IF EXISTS mk_forbid_mutation();');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('notification_logs');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('content_blocks');
        Schema::dropIfExists('content_pages');
    }
};
