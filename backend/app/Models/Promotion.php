<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Promotion extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'code', 'name', 'description', 'discount_type', 'discount_value', 'min_subtotal_xof', 'max_discount_xof',
        'starts_at', 'ends_at', 'usage_limit', 'active', 'is_public', 'is_demo',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean', 'is_public' => 'boolean',
            'is_demo' => 'boolean', 'discount_value' => 'integer', 'min_subtotal_xof' => 'integer',
            'max_discount_xof' => 'integer', 'usage_limit' => 'integer', 'used_count' => 'integer',
        ];
    }

    public function setCodeAttribute(string $value): void
    {
        $this->attributes['code'] = strtoupper(trim($value));
    }
}
