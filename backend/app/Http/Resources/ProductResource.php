<?php

namespace App\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\MissingValue;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $admin = $request->is('api/v1/admin/*');
        $variants = $this->whenLoaded('variants', fn () => $this->variants->filter(fn ($v) => $admin || ! $v->trashed())->values());
        $prices = $this->relationLoaded('variants') ? $this->variants->where('available', true)->pluck('price_xof') : collect();
        $detail = $request->routeIs('catalog.products.show') || $admin;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'tagline' => $this->tagline,
            'category' => $this->whenLoaded('category', fn () => ['id' => $this->category->id, 'name' => $this->category->name, 'slug' => $this->category->slug]),
            'price_from_xof' => $prices->min(),
            'is_featured' => $this->is_featured,
            'is_new' => $this->is_new,
            'is_signature' => $this->is_signature,
            'available' => $this->relationLoaded('variants') ? $this->variants->contains(fn ($v) => $v->available && ($v->stock_qty === null || $v->stock_qty > 0)) : null,
            'allergens' => $this->allergens ?? [],
            'requires_cold_chain' => $this->requires_cold_chain,
            'is_demo' => $this->is_demo,
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => [
                'id' => $i->id,
                'url' => $i->url('lg'),
                'srcset' => collect($i->variants ?? [])->map(fn ($p, $size) => $i->url($size).' '.['sm' => 480, 'md' => 960, 'lg' => 1600][$size].'w')->implode(', ') ?: null,
                'alt' => $i->alt,
                'width' => $i->width,
                'height' => $i->height,
                'is_illustrative' => $i->is_illustrative,
            ])),
            'variants' => $variants instanceof MissingValue ? $variants : $variants->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku,
                'label' => $v->label,
                'flavor' => $v->flavor,
                'size_label' => $v->size_label,
                'serves' => $v->serves,
                'price_xof' => $v->price_xof,
                'compare_at_price_xof' => $v->compare_at_price_xof,
                'available' => $v->available && ($v->stock_qty === null || $v->stock_qty > 0),
                'prep_minutes' => $v->prep_minutes,
                'max_per_order' => $v->max_per_order,
                $this->mergeWhen($admin, fn () => ['stock_qty' => $v->stock_qty, 'available_flag' => $v->available, 'position' => $v->position, 'archived' => $v->trashed()]),
            ]),
            $this->mergeWhen($detail, fn () => [
                'description' => $this->description,
                'ingredients' => $this->ingredients,
                'storage_instructions' => $this->storage_instructions,
                'shelf_life_hours' => $this->shelf_life_hours,
                'seo_title' => $this->seo_title,
                'seo_description' => $this->seo_description,
                'related' => $this->whenLoaded('related', fn () => ProductResource::collection($this->related)),
                'rating' => $this->whenLoaded('reviews', fn () => [
                    'average' => $this->reviews->avg('stars') ? round($this->reviews->avg('stars'), 1) : null,
                    'count' => $this->reviews->count(),
                    'items' => $this->reviews->take(6)->map(fn ($r) => ['author' => $r->author_name, 'stars' => $r->stars, 'comment' => $r->comment, 'date' => $r->created_at->toIso8601String()])->values(),
                ]),
            ]),
            $this->mergeWhen($admin, fn () => [
                'status' => $this->status->value,
                'category_id' => $this->category_id,
                'position' => $this->position,
                'is_demo' => $this->is_demo,
                'archived' => $this->trashed(),
                'related_ids' => $this->whenLoaded('related', fn () => $this->related->pluck('id')),
                'updated_at' => $this->updated_at?->toIso8601String(),
            ]),
        ];
    }
}
