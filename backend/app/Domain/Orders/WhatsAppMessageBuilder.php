<?php

namespace App\Domain\Orders;

use App\Domain\Shared\Money;
use App\Domain\Shared\Settings;
use App\Models\Order;

/**
 * Message WhatsApp prérempli (lien wa.me). Contient la référence et le récapitulatif,
 * sans donnée personnelle superflue ni jeton (TRD §7).
 */
class WhatsAppMessageBuilder
{
    public function __construct(private readonly Settings $settings) {}

    public function message(Order $order): string
    {
        $lines = ['Bonjour MK Délices, je souhaite passer commande :', ''];
        foreach ($order->items as $item) {
            $lines[] = "• {$item->qty} × {$item->product_name_snapshot} – {$item->variant_label_snapshot} (".Money::format($item->line_total_xof).')';
        }
        $lines[] = '';
        $lines[] = 'Sous-total : '.Money::format($order->subtotal_xof);
        if ($order->discount_xof > 0) {
            $lines[] = 'Remise : -'.Money::format($order->discount_xof);
        }
        if ($order->zone_id) {
            $lines[] = 'Livraison ('.($order->zone?->name ?? 'zone').') : '.Money::format($order->delivery_fee_xof);
        }
        $lines[] = 'Total estimé : '.Money::format($order->total_xof).' (à confirmer par MK Délices)';
        $lines[] = '';
        $lines[] = "Référence : {$order->reference}";
        $lines[] = 'Merci de me confirmer la disponibilité et le créneau de livraison.';

        return implode("\n", $lines);
    }

    /** URL wa.me encodée, ou null si le numéro professionnel n'est pas encore configuré. */
    public function url(Order $order): ?string
    {
        $number = preg_replace('/\D+/', '', (string) $this->settings->get('business.whatsapp_number'));
        if ($number === '' || strlen($number) < 8) {
            return null;
        }

        return 'https://wa.me/'.$number.'?text='.rawurlencode($this->message($order));
    }
}
