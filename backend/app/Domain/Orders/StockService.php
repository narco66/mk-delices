<?php

namespace App\Domain\Orders;

use App\Domain\Shared\DomainException;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

/**
 * Réservation atomique du stock (variantes à stock suivi uniquement) : le stock ne devient jamais négatif.
 * Doit être appelé dans une transaction.
 */
class StockService
{
    public function reserveForOrder(Order $order): void
    {
        foreach ($order->items()->get() as $item) {
            $affected = DB::update(
                'UPDATE product_variants SET stock_qty = stock_qty - ?, updated_at = now() WHERE id = ? AND stock_qty IS NOT NULL AND stock_qty >= ?',
                [$item->qty, $item->variant_id, $item->qty],
            );
            if ($affected === 0) {
                $variant = ProductVariant::query()->withTrashed()->find($item->variant_id);
                if ($variant && $variant->stock_qty !== null) {
                    throw DomainException::conflict('insufficient_stock', "Stock insuffisant pour « {$item->product_name_snapshot} – {$item->variant_label_snapshot} ».", ['variant_id' => $item->variant_id, 'available' => $variant->stock_qty]);
                }
            }
        }
    }

    public function releaseForOrder(Order $order): void
    {
        foreach ($order->items()->get() as $item) {
            DB::update(
                'UPDATE product_variants SET stock_qty = stock_qty + ?, updated_at = now() WHERE id = ? AND stock_qty IS NOT NULL',
                [$item->qty, $item->variant_id],
            );
        }
    }
}
