<?php

namespace App\Models;

use App\Domain\Deliveries\AssignmentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeliveryAssignment extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    /** Missions des commandes réelles uniquement (voir Order::scopeNotTest). */
    public function scopeForRealOrders(Builder $query): Builder
    {
        return $query->whereHas('order', fn (Builder $o) => $o->notTest());
    }

    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'partner_fee_xof' => 'integer',
            'cash_to_collect_xof' => 'integer',
            'cash_collected_xof' => 'integer',
            'packaging_checked' => 'boolean',
            'contact_attempted' => 'boolean',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'refused_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'closed_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(DeliveryPartner::class, 'partner_id');
    }

    public function assigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status->value, AssignmentStatus::active(), true);
    }
}
