<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Facturation : factures et avoirs IMMUABLES, numérotation continue sans trou par série et par année.
 * Une facture émise ne se modifie ni ne se supprime : une correction passe par un avoir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->string('series', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('last_number')->default(0);
            $table->primary(['series', 'year']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();
            $table->string('type', 20); // invoice | credit_note
            $table->string('number', 40)->unique();
            $table->string('series', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('sequence');
            $table->uuid('original_invoice_id')->nullable();
            $table->foreignUuid('refund_id')->nullable()->unique()->constrained('refunds')->restrictOnDelete();
            $table->timestampTz('issued_at');
            $table->string('currency', 3)->default('XOF');
            $table->boolean('vat_applicable');
            $table->unsignedInteger('vat_rate_bp')->default(0); // points de base : 1800 = 18,00 %
            $table->bigInteger('total_ht_xof');
            $table->bigInteger('total_vat_xof');
            $table->bigInteger('total_ttc_xof');
            $table->jsonb('seller');
            $table->jsonb('buyer');
            $table->jsonb('lines');
            $table->text('legal_note')->nullable();
            $table->boolean('is_test')->default(false); // document hors production : sans valeur fiscale
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['series', 'year', 'sequence']);
            $table->index(['type', 'issued_at']);
        });

        // Clé étrangère auto-référencée ajoutée après la création (la clé primaire doit exister).
        Schema::table('invoices', fn (Blueprint $table) => $table->foreign('original_invoice_id')->references('id')->on('invoices')->restrictOnDelete());
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_type CHECK (type IN ('invoice', 'credit_note'))");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_totals CHECK (total_ttc_xof > 0 AND total_ht_xof >= 0 AND total_vat_xof >= 0 AND total_ttc_xof = total_ht_xof + total_vat_xof)');
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT chk_invoices_credit_note CHECK ((type = 'credit_note') = (original_invoice_id IS NOT NULL))");
        // Une seule facture par commande (les avoirs, eux, peuvent être multiples).
        DB::statement("CREATE UNIQUE INDEX uq_invoices_one_per_order ON invoices (order_id) WHERE type = 'invoice'");
        // mk_forbid_mutation() est créée par la migration des journaux (audit_logs, order_events).
        DB::unprepared('CREATE TRIGGER trg_invoices_immutable BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION mk_forbid_mutation();');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_invoices_immutable ON invoices;');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_sequences');
    }
};
