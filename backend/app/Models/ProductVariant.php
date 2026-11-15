<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductVariant extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'product_id', 'sku', 'label', 'flavor', 'size_label', 'serves', 'price_xof', 'compare_at_price_xof',
        'available', 'prep_minutes', 'stock_qty', 'max_per_order', 'position',
    ];

    protected function casts(): array
    {
        return [
            'price_xof' => 'integer',
            'compare_at_price_xof' => 'integer',
            'available' => 'boolean',
            'prep_minutes' => 'integer',
            'stock_qty' => 'integer',
            'max_per_order' => 'integer',
            'serves' => 'integer',
            'version' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function isSellable(): bool
    {
        return $this->available && ! $this->trashed()
            && ($this->stock_qty === null || $this->stock_qty > 0)
            && $this->product !== null && $this->product->isSellable();
    }
}
