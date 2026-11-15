<?php

namespace App\Models;

use App\Domain\Catalog\ProductStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'category_id', 'slug', 'name', 'tagline', 'description', 'ingredients', 'allergens',
        'storage_instructions', 'shelf_life_hours', 'requires_cold_chain', 'status', 'is_featured',
        'is_new', 'is_signature', 'position', 'seo_title', 'seo_description', 'is_demo', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'allergens' => 'array',
            'status' => ProductStatus::class,
            'requires_cold_chain' => 'boolean',
            'is_featured' => 'boolean',
            'is_new' => 'boolean',
            'is_signature' => 'boolean',
            'is_demo' => 'boolean',
            'published_at' => 'datetime',
            'shelf_life_hours' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort_order');
    }

    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'product_relations', 'product_id', 'related_product_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /** Produit visible et commandable par le public. */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where('status', ProductStatus::Published->value)
            ->whereHas('category', fn (Builder $q) => $q->where('active', true));
    }

    public function isSellable(): bool
    {
        return $this->status === ProductStatus::Published && ! $this->trashed() && ($this->category?->active ?? false);
    }
}
