<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class DeliveryZone extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'slug', 'description', 'districts', 'active', 'delivery_fee_xof', 'min_order_xof',
        'partner_fee_xof', 'extra_lead_minutes', 'position', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean', 'is_demo' => 'boolean', 'delivery_fee_xof' => 'integer',
            'min_order_xof' => 'integer', 'partner_fee_xof' => 'integer', 'extra_lead_minutes' => 'integer',
        ];
    }

    public function partners(): BelongsToMany
    {
        return $this->belongsToMany(DeliveryPartner::class, 'delivery_partner_zones', 'zone_id', 'partner_id');
    }
}
