<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\DomainException;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Téléversement sécurisé (SEC-004) : type MIME réel contrôlé, image décodée puis ré-encodée en WebP
 * (suppression des métadonnées EXIF et de tout contenu non image), variantes responsives.
 */
class ProductImageService
{
    private const SIZES = ['sm' => 480, 'md' => 960, 'lg' => 1600];

    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function store(Product $product, UploadedFile $file, string $alt, bool $illustrative = false): ProductImage
    {
        $mime = $file->getMimeType();
        if (! in_array($mime, self::ALLOWED_MIME, true)) {
            throw DomainException::unprocessable('invalid_image', 'Format d\'image non autorisé (JPEG, PNG ou WebP uniquement).');
        }
        try {
            $manager = ImageManager::usingDriver(Driver::class);
            $image = $manager->decodePath($file->getRealPath());
        } catch (Throwable) {
            throw DomainException::unprocessable('invalid_image', 'Le fichier n\'est pas une image valide.');
        }
        if ($image->width() < 400 || $image->height() < 400 || $image->width() > 8000 || $image->height() > 8000) {
            throw DomainException::unprocessable('invalid_image_dimensions', 'L\'image doit mesurer entre 400 et 8000 pixels de côté.');
        }

        $base = 'products/'.$product->id.'/'.Str::uuid();
        $variants = [];
        foreach (self::SIZES as $name => $width) {
            $copy = (clone $image)->scaleDown(width: $width);
            $path = "{$base}-{$name}.webp";
            Storage::disk('public')->put($path, $copy->encode(new WebpEncoder(quality: 82, strip: true))->toString());
            $variants[$name] = $path;
        }

        return $product->images()->create([
            'path' => $variants['lg'],
            'variants' => $variants,
            'alt' => $alt,
            'width' => min($image->width(), self::SIZES['lg']),
            'height' => (int) round($image->height() * min($image->width(), self::SIZES['lg']) / $image->width()),
            'sort_order' => (int) $product->images()->max('sort_order') + 1,
            'is_illustrative' => $illustrative,
        ]);
    }

    public function delete(ProductImage $image): void
    {
        foreach (array_unique(array_merge([$image->path], array_values($image->variants ?? []))) as $path) {
            if (! str_starts_with($path, '/') && ! str_starts_with($path, 'http')) {
                Storage::disk('public')->delete($path);
            }
        }
        $image->delete();
    }
}
