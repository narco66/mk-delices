<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Catalog\ProductImageService;
use App\Domain\Catalog\ProductStatus;
use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Administration du catalogue : créer, modifier, publier, désactiver, archiver.
 * Les changements de prix sont journalisés et n'affectent jamais les commandes existantes (T-016).
 */
class ProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['q' => ['nullable', 'string', 'max:80'], 'status' => ['nullable', 'string'], 'category_id' => ['nullable', 'uuid'], 'archived' => ['nullable', 'boolean']]);
        $query = Product::query()->with(['category', 'variants' => fn ($q) => $q->withTrashed(), 'images'])
            ->when($request->boolean('archived'), fn ($q) => $q->onlyTrashed())
            ->when($request->input('q'), fn ($q, $t) => $q->where('name', 'ilike', '%'.addcslashes($t, '%_\\').'%'))
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', explode(',', $s)))
            ->when($request->input('category_id'), fn ($q, $c) => $q->where('category_id', $c))
            ->orderBy('position')->orderBy('name');

        return ProductResource::collection($query->paginate(50));
    }

    public function show(string $id): ProductResource
    {
        return new ProductResource(Product::withTrashed()->with(['category', 'variants' => fn ($q) => $q->withTrashed(), 'images', 'related'])->findOrFail($id));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $product = DB::transaction(function () use ($data) {
            $product = Product::query()->create($this->productAttributes($data));
            $this->syncVariants($product, $data['variants']);
            $product->related()->sync($data['related_ids'] ?? []);

            return $product;
        });
        $this->audit->log('product.created', $product, null, ['name' => $product->name, 'variants' => $data['variants']]);

        return (new ProductResource($product->load(['category', 'variants', 'images', 'related'])))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): ProductResource
    {
        $product = Product::query()->findOrFail($id);
        $data = $this->validated($request, $product);
        $before = ['product' => $product->only(['name', 'status', 'slug']), 'variants' => $product->variants()->get(['sku', 'price_xof', 'available', 'stock_qty'])->toArray()];
        DB::transaction(function () use ($product, $data) {
            $product->update($this->productAttributes($data, $product));
            $this->syncVariants($product, $data['variants']);
            $product->related()->sync(array_values(array_diff($data['related_ids'] ?? [], [$product->id])));
        });
        $this->audit->log('product.updated', $product, $before, ['product' => $product->only(['name', 'status', 'slug']), 'variants' => $product->variants()->get(['sku', 'price_xof', 'available', 'stock_qty'])->toArray()]);

        return $this->show($product->id);
    }

    /** Publication / désactivation : un produit non publié n'est plus commandable (CAT-001). */
    public function setStatus(Request $request, string $id): ProductResource
    {
        $data = $request->validate(['status' => ['required', Rule::enum(ProductStatus::class)->except([ProductStatus::Archived])]]);
        $product = Product::query()->findOrFail($id);
        if ($data['status'] === 'published' && ! $product->variants()->where('available', true)->exists()) {
            throw DomainException::unprocessable('no_sellable_variant', 'Ajoutez au moins un format disponible avant de publier.');
        }
        if ($data['status'] === 'published' && empty($product->allergens) && trim((string) $product->ingredients) === '') {
            throw DomainException::unprocessable('allergens_required', 'Renseignez ingrédients et allergènes avant publication (CAT-001).');
        }
        $before = $product->status->value;
        $product->update(['status' => $data['status'], 'published_at' => $data['status'] === 'published' ? ($product->published_at ?? now()) : $product->published_at]);
        $this->audit->log('product.status', $product, ['status' => $before], ['status' => $data['status']]);

        return $this->show($product->id);
    }

    /** Archivage (suppression logique) : conserve l'historique des commandes. */
    public function archive(string $id): Response
    {
        $product = Product::query()->findOrFail($id);
        $product->update(['status' => ProductStatus::Archived]);
        $product->delete();
        $this->audit->log('product.archived', $product);

        return response()->noContent();
    }

    public function restore(string $id): ProductResource
    {
        $product = Product::onlyTrashed()->findOrFail($id);
        $product->restore();
        $product->update(['status' => ProductStatus::Inactive]);
        $this->audit->log('product.restored', $product);

        return $this->show($id);
    }

    public function uploadImage(Request $request, string $id, ProductImageService $images): JsonResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'max:6144', 'mimetypes:image/jpeg,image/png,image/webp'],
            'alt' => ['required', 'string', 'min:3', 'max:200'],
            'is_illustrative' => ['nullable', 'boolean'],
        ]);
        $product = Product::query()->findOrFail($id);
        $image = $images->store($product, $data['image'], $data['alt'], $request->boolean('is_illustrative'));
        $this->audit->log('product.image_added', $product, null, ['image_id' => $image->id]);

        return response()->json(['data' => ['id' => $image->id, 'url' => $image->url('md'), 'alt' => $image->alt]], 201);
    }

    public function updateImage(Request $request, string $id, ProductImage $image): JsonResponse
    {
        abort_unless($image->product_id === $id, 404);
        $data = $request->validate(['alt' => ['required', 'string', 'min:3', 'max:200'], 'sort_order' => ['nullable', 'integer', 'min:0']]);
        $image->update($data);

        return response()->json(['data' => ['id' => $image->id, 'alt' => $image->alt, 'sort_order' => $image->sort_order]]);
    }

    public function deleteImage(string $id, ProductImage $image, ProductImageService $images): Response
    {
        abort_unless($image->product_id === $id, 404);
        $images->delete($image);
        $this->audit->log('product.image_deleted', $image->product, ['image_id' => $image->id]);

        return response()->noContent();
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'category_id' => ['required', 'uuid', 'exists:categories,id'],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['nullable', 'string', 'max:160', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('products', 'slug')->ignore($product?->id)],
            'tagline' => ['nullable', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'ingredients' => ['nullable', 'string', 'max:3000'],
            'allergens' => ['present', 'array', 'max:20'],
            'allergens.*' => ['string', 'max:40'],
            'storage_instructions' => ['nullable', 'string', 'max:1000'],
            'shelf_life_hours' => ['nullable', 'integer', 'min:1', 'max:2160'],
            'requires_cold_chain' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_new' => ['boolean'],
            'is_signature' => ['boolean'],
            'position' => ['nullable', 'integer'],
            'seo_title' => ['nullable', 'string', 'max:70'],
            'seo_description' => ['nullable', 'string', 'max:170'],
            'related_ids' => ['nullable', 'array', 'max:12'],
            'related_ids.*' => ['uuid', 'exists:products,id'],
            'variants' => ['required', 'array', 'min:1', 'max:20'],
            'variants.*.id' => ['nullable', 'uuid'],
            'variants.*.sku' => ['required', 'string', 'max:60', 'regex:/^[A-Z0-9\-_]+$/', 'distinct'],
            'variants.*.label' => ['required', 'string', 'max:120'],
            'variants.*.flavor' => ['nullable', 'string', 'max:80'],
            'variants.*.size_label' => ['nullable', 'string', 'max:60'],
            'variants.*.serves' => ['nullable', 'integer', 'min:1', 'max:200'],
            'variants.*.price_xof' => ['required', 'integer', 'min:0', 'max:100000000'],
            'variants.*.compare_at_price_xof' => ['nullable', 'integer', 'min:0'],
            'variants.*.available' => ['boolean'],
            'variants.*.prep_minutes' => ['nullable', 'integer', 'min:0', 'max:20160'],
            'variants.*.stock_qty' => ['nullable', 'integer', 'min:0'],
            'variants.*.max_per_order' => ['nullable', 'integer', 'min:1', 'max:500'],
            'variants.*.archived' => ['nullable', 'boolean'],
        ]);
    }

    private function productAttributes(array $data, ?Product $product = null): array
    {
        return [
            'category_id' => $data['category_id'],
            'name' => $data['name'],
            'slug' => $data['slug'] ?? ($product?->slug ?? $this->uniqueSlug($data['name'])),
            'tagline' => $data['tagline'] ?? null,
            'description' => $data['description'] ?? null,
            'ingredients' => $data['ingredients'] ?? null,
            'allergens' => array_values(array_unique(array_map('mb_strtolower', $data['allergens']))),
            'storage_instructions' => $data['storage_instructions'] ?? null,
            'shelf_life_hours' => $data['shelf_life_hours'] ?? null,
            'requires_cold_chain' => $data['requires_cold_chain'] ?? false,
            'is_featured' => $data['is_featured'] ?? false,
            'is_new' => $data['is_new'] ?? false,
            'is_signature' => $data['is_signature'] ?? false,
            'position' => $data['position'] ?? 0,
            'seo_title' => $data['seo_title'] ?? null,
            'seo_description' => $data['seo_description'] ?? null,
        ] + ($product ? [] : ['status' => ProductStatus::Draft]);
    }

    private function syncVariants(Product $product, array $variants): void
    {
        $kept = [];
        foreach (array_values($variants) as $position => $v) {
            $attributes = [
                'sku' => $v['sku'], 'label' => $v['label'], 'flavor' => $v['flavor'] ?? null, 'size_label' => $v['size_label'] ?? null,
                'serves' => $v['serves'] ?? null, 'price_xof' => $v['price_xof'], 'compare_at_price_xof' => $v['compare_at_price_xof'] ?? null,
                'available' => $v['available'] ?? true, 'prep_minutes' => $v['prep_minutes'] ?? 0, 'stock_qty' => $v['stock_qty'] ?? null,
                'max_per_order' => $v['max_per_order'] ?? null, 'position' => $position,
            ];
            $variant = ! empty($v['id']) ? ProductVariant::withTrashed()->where('product_id', $product->id)->find($v['id']) : null;
            if ($variant) {
                if (ProductVariant::withTrashed()->where('sku', $v['sku'])->where('id', '!=', $variant->id)->exists()) {
                    throw DomainException::unprocessable('sku_taken', "Le SKU {$v['sku']} est déjà utilisé.");
                }
                $variant->fill($attributes);
                if ($variant->isDirty('price_xof')) {
                    $variant->version++;
                }
                $variant->save();
            } else {
                if (ProductVariant::withTrashed()->where('sku', $v['sku'])->exists()) {
                    throw DomainException::unprocessable('sku_taken', "Le SKU {$v['sku']} est déjà utilisé.");
                }
                $variant = $product->variants()->create($attributes);
            }
            ! empty($v['archived']) ? $variant->delete() : ($variant->trashed() ? $variant->restore() : null);
            $kept[] = $variant->id;
        }
        // Formats retirés : archivés (jamais supprimés, ils peuvent figurer dans d'anciennes commandes).
        $product->variants()->whereNotIn('id', $kept)->get()->each->delete();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'produit';
        $slug = $base;
        for ($i = 2; Product::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
