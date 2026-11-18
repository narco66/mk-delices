<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function categories(): JsonResponse
    {
        $categories = Category::query()->where('active', true)->orderBy('position')
            ->withCount(['products' => fn ($q) => $q->publiclyVisible()])
            ->get(['id', 'name', 'slug', 'description', 'image_path']);

        return response()->json(['data' => $categories]);
    }

    public function products(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:140'],
            'sort' => ['nullable', 'in:featured,price_asc,price_desc,newest,name'],
            'featured' => ['nullable', 'boolean'],
            'new' => ['nullable', 'boolean'],
            'signature' => ['nullable', 'boolean'],
            'available' => ['nullable', 'boolean'],
            'allergen_free' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()->publiclyVisible()->with(['category', 'variants', 'images'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('tagline', 'ilike', '%'.addcslashes($term, '%_\\').'%')
                ->orWhere('description', 'ilike', '%'.addcslashes($term, '%_\\').'%')))
            ->when($filters['category'] ?? null, fn ($q, $slug) => $q->whereHas('category', fn ($c) => $c->where('slug', $slug)))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->boolean('new'), fn ($q) => $q->where('is_new', true))
            ->when($request->boolean('signature'), fn ($q) => $q->where('is_signature', true))
            ->when($request->boolean('available'), fn ($q) => $q->whereHas('variants', fn ($v) => $v->where('available', true)->where(fn ($s) => $s->whereNull('stock_qty')->orWhere('stock_qty', '>', 0))))
            ->when($filters['allergen_free'] ?? null, fn ($q, $a) => $q->whereRaw('NOT (allergens @> ?::jsonb)', [json_encode([$a])]));

        $minPrice = '(SELECT min(price_xof) FROM product_variants v WHERE v.product_id = products.id AND v.deleted_at IS NULL)';
        match ($filters['sort'] ?? 'featured') {
            'price_asc' => $query->orderByRaw("{$minPrice} ASC"),
            'price_desc' => $query->orderByRaw("{$minPrice} DESC"),
            'newest' => $query->orderByDesc('published_at'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('is_signature')->orderByDesc('is_featured')->orderBy('position'),
        };

        return ProductResource::collection($query->paginate($filters['per_page'] ?? 24)->withQueryString());
    }

    public function show(string $slug): ProductResource
    {
        $product = Product::query()->publiclyVisible()->where('slug', $slug)
            ->with(['category', 'variants', 'images', 'related' => fn ($q) => $q->publiclyVisible()->with(['variants', 'images', 'category']),
                // Avis des commandes de test (démonstration archivée) jamais publiés.
                'reviews' => fn ($q) => $q->where('moderation_status', 'approved')
                    ->where(fn ($r) => $r->whereNull('order_id')->orWhereHas('order', fn ($o) => $o->notTest()))->latest()])
            ->firstOrFail();

        return new ProductResource($product);
    }
}
