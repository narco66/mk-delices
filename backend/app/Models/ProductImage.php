<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    use HasUuids;

    protected $fillable = ['product_id', 'path', 'variants', 'alt', 'width', 'height', 'sort_order', 'is_illustrative'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'is_illustrative' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function url(?string $size = null): string
    {
        $path = $size && isset($this->variants[$size]) ? $this->variants[$size] : $this->path;
        if (str_starts_with($path, 'http') || str_starts_with($path, '/')) {
            return $path;
        }

        return Storage::disk('public')->url($path);
    }
}
