<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    use HasUuids;

    protected $fillable = ['customer_id', 'zone_id', 'label', 'district', 'street', 'landmark', 'lat', 'lng', 'location_source', 'location_accuracy_m', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'lat' => 'float', 'lng' => 'float', 'location_accuracy_m' => 'integer'];
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
