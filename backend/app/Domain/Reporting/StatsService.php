<?php

namespace App\Domain\Reporting;

use App\Domain\Shared\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Indicateurs de pilotage calculés depuis PostgreSQL (PRD §2, STATS-010).
 * Les commandes de test (is_test) sont exclues. Les ventes = commandes payées, nettes des remboursements.
 */
class StatsService
{
    /**
     * Vente réelle (audit lot 3) : commande payée, non annulée ni refusée (une commande annulée encore
     * « payée » attend son remboursement, ce n'est pas du chiffre d'affaires) et pas intégralement remboursée.
     */
    private const SALE = "payment_status IN ('paid','partially_refunded') AND status NOT IN ('cancelled','rejected')";

    /** Même filtre, sur la table aliasée « o ». */
    private const SALE_O = "o.payment_status IN ('paid','partially_refunded') AND o.status NOT IN ('cancelled','rejected')";

    /** Montant net retenu par vente : encaissé moins remboursé, jamais au-delà du total (trop-perçu exclu). */
    private const NET = 'LEAST(paid_xof - refunded_xof, total_xof)';

    public function __construct(private readonly Settings $settings) {}

    public function summary(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tz = $this->settings->timezone();
        $range = [$from->utc(), $to->utc()];

        $sales = DB::selectOne(
            'SELECT count(*) FILTER (WHERE '.self::SALE.') AS paid_orders,
                    coalesce(sum('.self::NET.') FILTER (WHERE '.self::SALE.'), 0) AS revenue_net,
                    count(*) FILTER (WHERE status <> \'draft\') AS confirmed_orders,
                    count(*) FILTER (WHERE payment_status IN (\'refunded\',\'partially_refunded\')) AS refunded_orders,
                    count(*) FILTER (WHERE status = \'cancelled\') AS cancelled_orders,
                    count(*) FILTER (WHERE status = \'rejected\') AS rejected_orders,
                    avg(extract(epoch FROM (ready_at - accepted_at)) / 60) FILTER (WHERE ready_at IS NOT NULL AND accepted_at IS NOT NULL) AS avg_prep_minutes
             FROM orders WHERE is_test = false AND coalesce(confirmed_at, created_at) BETWEEN ? AND ?',
            $range,
        );

        $deliveries = DB::selectOne(
            "SELECT count(*) FILTER (WHERE a.status = 'delivered') AS delivered,
                    count(*) FILTER (WHERE a.status = 'failed') AS failed
             FROM delivery_assignments a JOIN orders o ON o.id = a.order_id
             WHERE o.is_test = false AND a.closed_at BETWEEN ? AND ?",
            $range,
        );

        $carts = DB::selectOne(
            'SELECT count(DISTINCT c.id) AS with_items, count(DISTINCT c.id) FILTER (WHERE c.converted_order_id IS NOT NULL) AS converted
             FROM carts c JOIN cart_items i ON i.cart_id = c.id WHERE c.created_at BETWEEN ? AND ?',
            $range,
        );

        $paidOrders = (int) $sales->paid_orders;
        $closedMissions = (int) $deliveries->delivered + (int) $deliveries->failed;
        $cartsWithItems = (int) $carts->with_items;

        return [
            'period' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'timezone' => $tz],
            'kpis' => [
                'revenue_net_xof' => (int) $sales->revenue_net,
                'paid_orders' => $paidOrders,
                'confirmed_orders' => (int) $sales->confirmed_orders,
                'average_basket_xof' => $paidOrders > 0 ? intdiv((int) $sales->revenue_net, $paidOrders) : null,
                'delivery_success_rate' => $closedMissions > 0 ? round((int) $deliveries->delivered / $closedMissions, 4) : null,
                'deliveries_delivered' => (int) $deliveries->delivered,
                'deliveries_failed' => (int) $deliveries->failed,
                'avg_prep_minutes' => $sales->avg_prep_minutes !== null ? (int) round((float) $sales->avg_prep_minutes) : null,
                'refund_rate' => $paidOrders > 0 ? round((int) $sales->refunded_orders / $paidOrders, 4) : null,
                'cancelled_orders' => (int) $sales->cancelled_orders,
                'rejected_orders' => (int) $sales->rejected_orders,
                'cart_abandonment_rate' => $cartsWithItems > 0 ? round(1 - (int) $carts->converted / $cartsWithItems, 4) : null,
                // Les sessions qualifiées ne sont pas mesurées : aucun outil d'analytics n'est encore validé (D-STATS-01).
                'conversion_rate' => null,
            ],
            'timeseries' => $this->timeseries($from, $to, $tz),
            'top_products' => $this->topProducts($range),
            'status_breakdown' => DB::select('SELECT status, count(*)::int AS count FROM orders WHERE is_test = false AND coalesce(confirmed_at, created_at) BETWEEN ? AND ? GROUP BY status ORDER BY count DESC', $range),
            'by_zone' => $this->byZone($range),
        ];
    }

    /**
     * Indicateurs logistiques par zone (docs/audit-geolocalisation) : uniquement des agrégats, jamais de position
     * individuelle. « positioned » = commandes avec position GPS/carte ; « declared » = zone choisie par le client.
     */
    private function byZone(array $range): array
    {
        $rows = DB::select(
            "SELECT z.id AS zone_id, coalesce(z.name, 'Retrait / sans zone') AS zone_name,
                    count(*) FILTER (WHERE o.status <> 'draft')::int AS orders,
                    count(*) FILTER (WHERE ".self::SALE_O.')::int AS paid_orders,
                    coalesce(sum(LEAST(o.paid_xof - o.refunded_xof, o.total_xof)) FILTER (WHERE '.self::SALE_O.'), 0)::bigint AS revenue_net_xof,
                    coalesce(sum(o.delivery_fee_xof) FILTER (WHERE '.self::SALE_O."), 0)::bigint AS delivery_fees_xof,
                    count(*) FILTER (WHERE o.address_snapshot->>'lat' IS NOT NULL OR o.address_snapshot->>'position_purged_at' IS NOT NULL)::int AS positioned,
                    count(*) FILTER (WHERE o.address_snapshot->>'zone_source' = 'declared')::int AS declared,
                    coalesce(sum(a.delivered), 0)::int AS delivered,
                    coalesce(sum(a.failed), 0)::int AS failed,
                    coalesce(sum(a.late), 0)::int AS late,
                    sum(a.trip_minutes) / nullif(count(a.trip_minutes), 0) AS avg_trip_minutes
             FROM orders o
             LEFT JOIN delivery_zones z ON z.id = o.zone_id
             -- Missions agrégées PAR COMMANDE (un échec suivi d'une relivraison ne double pas la commande).
             LEFT JOIN (
                SELECT a.order_id,
                       count(*) FILTER (WHERE a.status = 'delivered') AS delivered,
                       count(*) FILTER (WHERE a.status = 'failed') AS failed,
                       count(*) FILTER (WHERE a.status = 'delivered' AND a.delivered_at > oo.slot_end_at) AS late,
                       avg(extract(epoch FROM (a.delivered_at - a.picked_up_at)) / 60) FILTER (WHERE a.status = 'delivered' AND a.picked_up_at IS NOT NULL) AS trip_minutes
                FROM delivery_assignments a JOIN orders oo ON oo.id = a.order_id
                WHERE a.status IN ('delivered', 'failed')
                GROUP BY a.order_id
             ) a ON a.order_id = o.id
             WHERE o.is_test = false AND coalesce(o.confirmed_at, o.created_at) BETWEEN ? AND ?
             GROUP BY z.id, z.name
             ORDER BY orders DESC",
            $range,
        );

        return array_map(fn ($r) => [
            'zone_id' => $r->zone_id, 'zone_name' => $r->zone_name, 'orders' => $r->orders, 'paid_orders' => $r->paid_orders,
            'revenue_net_xof' => (int) $r->revenue_net_xof, 'delivery_fees_xof' => (int) $r->delivery_fees_xof,
            'positioned' => $r->positioned, 'declared' => $r->declared, 'delivered' => $r->delivered, 'failed' => $r->failed, 'late' => $r->late,
            'avg_trip_minutes' => $r->avg_trip_minutes !== null ? (int) round((float) $r->avg_trip_minutes) : null,
        ], $rows);
    }

    /** Indicateurs opérationnels instantanés (indépendants de la période). */
    public function operations(): array
    {
        $row = DB::selectOne(
            "SELECT count(*) FILTER (WHERE status = 'pending_confirmation') AS to_confirm,
                    count(*) FILTER (WHERE status = 'draft') AS whatsapp_drafts,
                    count(*) FILTER (WHERE status IN ('accepted','preparing')) AS in_preparation,
                    count(*) FILTER (WHERE status = 'ready') AS ready_to_assign,
                    count(*) FILTER (WHERE status IN ('assigned','picked_up')) AS deliveries_in_progress,
                    count(*) FILTER (WHERE status = 'delivery_failed') AS deliveries_failed,
                    count(*) FILTER (WHERE payment_status = 'pending') AS payments_pending
             FROM orders WHERE is_test = false",
        );
        $refunds = (int) DB::table('refunds')->whereIn('status', ['requested', 'approved'])->count();

        return array_map('intval', (array) $row) + ['refunds_pending' => $refunds];
    }

    private function timeseries(CarbonImmutable $from, CarbonImmutable $to, string $tz): array
    {
        $rows = DB::select(
            'SELECT d::date AS day,
                    coalesce(sum(LEAST(o.paid_xof - o.refunded_xof, o.total_xof)) FILTER (WHERE '.self::SALE_O.'), 0)::bigint AS revenue_net,
                    count(o.id) FILTER (WHERE o.status <> \'draft\')::int AS orders
             FROM generate_series(?::date, ?::date, interval \'1 day\') d
             LEFT JOIN orders o ON o.is_test = false AND (coalesce(o.confirmed_at, o.created_at) AT TIME ZONE ?)::date = d::date
             GROUP BY d ORDER BY d',
            [$from->setTimezone($tz)->toDateString(), $to->setTimezone($tz)->toDateString(), $tz],
        );

        return array_map(fn ($r) => ['day' => $r->day, 'revenue_net_xof' => (int) $r->revenue_net, 'orders' => (int) $r->orders], $rows);
    }

    private function topProducts(array $range): array
    {
        $rows = DB::select(
            'SELECT i.product_id, max(i.product_name_snapshot) AS name, sum(i.qty)::int AS quantity, sum(i.line_total_xof)::bigint AS revenue
             FROM order_items i JOIN orders o ON o.id = i.order_id
             WHERE o.is_test = false AND '.self::SALE_O.' AND coalesce(o.confirmed_at, o.created_at) BETWEEN ? AND ?
             GROUP BY i.product_id ORDER BY quantity DESC LIMIT 5',
            $range,
        );

        return array_map(fn ($r) => ['product_id' => $r->product_id, 'name' => $r->name, 'quantity' => (int) $r->quantity, 'revenue_xof' => (int) $r->revenue], $rows);
    }
}
