<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Audit M15 : index manquants. PostgreSQL n'indexe pas automatiquement les clés étrangères ; sans eux,
 * la fiche commande (articles, paiements, remboursements, missions, factures), l'historique client, le
 * catalogue et les statistiques parcourent les tables entières. Migration purement additive.
 */
return new class extends Migration
{
    private const INDEXES = [
        'idx_order_items_order' => 'order_items (order_id)',
        'idx_orders_customer' => 'orders (customer_id)',
        // Statistiques : période sur la date de confirmation, hors commandes de test.
        'idx_orders_stats_date' => 'orders ((coalesce(confirmed_at, created_at))) WHERE is_test = false',
        'idx_payments_order' => 'payments (order_id)',
        'idx_payment_events_payment' => 'payment_events (payment_id)',
        'idx_refunds_order' => 'refunds (order_id)',
        'idx_refunds_payment' => 'refunds (payment_id)',
        'idx_refunds_status_created' => 'refunds (status, created_at)',
        'idx_delivery_assignments_order' => 'delivery_assignments (order_id)',
        'idx_invoices_order' => 'invoices (order_id)',
        'idx_product_variants_product' => 'product_variants (product_id)',
        'idx_product_images_product' => 'product_images (product_id)',
        'idx_addresses_customer' => 'addresses (customer_id)',
        'idx_reviews_product_status' => 'reviews (product_id, moderation_status)',
        'idx_notification_logs_order' => 'notification_logs (order_id)',
        'idx_carts_expires' => 'carts (expires_at)',
        'idx_idempotency_keys_expires' => 'idempotency_keys (expires_at)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEXES) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
