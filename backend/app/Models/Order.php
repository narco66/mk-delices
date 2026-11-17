<?php

namespace App\Models;

use App\Domain\Deliveries\AssignmentStatus;
use App\Domain\Orders\OrderPaymentStatus;
use App\Domain\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory, HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['tracking_token_hash', 'delivery_code'];

    protected static function booted(): void
    {
        // Code de remise à 4 chiffres (décision du 17/11/2026), à donner au livreur à la réception.
        static::creating(function (Order $order) {
            $order->delivery_code ??= str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
        });
    }

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'payment_status' => OrderPaymentStatus::class,
            'customer_snapshot' => 'array',
            'address_snapshot' => 'array',
            'subtotal_xof' => 'integer',
            'discount_xof' => 'integer',
            'delivery_fee_xof' => 'integer',
            'total_xof' => 'integer',
            'paid_xof' => 'integer',
            'refunded_xof' => 'integer',
            'version' => 'integer',
            'is_test' => 'boolean',
            'details_locked' => 'boolean',
            'slot_start_at' => 'datetime',
            'slot_end_at' => 'datetime',
            'tracking_token_expires_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'accepted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class)->orderBy('occurred_at');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('created_at');
    }

    /** Facture de vente (une seule par commande). */
    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class)->where('type', Invoice::TYPE_INVOICE);
    }

    /** Facture et avoirs, dans l'ordre d'émission. */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->orderBy('issued_at')->orderBy('sequence');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(DeliveryAssignment::class)->orderBy('assigned_at');
    }

    public function activeAssignment(): HasOne
    {
        // Au plus une mission active (index unique partiel) : pas besoin d'agrégat ofMany (MAX(uuid) invalide en PostgreSQL).
        return $this->hasOne(DeliveryAssignment::class)->whereIn('status', AssignmentStatus::active());
    }

    public function recipientName(): string
    {
        return trim(($this->customer_snapshot['first_name'] ?? '').' '.($this->customer_snapshot['last_name'] ?? ''));
    }
}
