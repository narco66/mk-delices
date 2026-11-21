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
        // Adresse ABSOLUE construite sur l'hôte réellement utilisé par le client (url() : schéma et hôte de la
        // requête, APP_URL hors requête). Avant le 21/11/2026, elle reprenait APP_URL : une application mobile qui
        // joint l'API par 10.0.2.2:8000 ou l'IP du poste recevait « http://localhost:8000/… », introuvable depuis
        // le téléphone. Le préfixe (/storage) reste celui du disque public.
        $prefix = trim((string) parse_url((string) Storage::disk('public')->url(''), PHP_URL_PATH), '/') ?: 'storage';

        return url($prefix.'/'.ltrim($path, '/'));
    }
}
