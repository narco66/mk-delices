<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** SEC-004 — téléversement contrôlé : MIME réel, dimensions, ré-encodage WebP, suppression des fichiers. */
class ProductImageUploadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_valid_image_is_reencoded_to_webp_variants(): void
    {
        $product = $this->variant()->product;
        $response = $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->post("/api/v1/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('tiramisu.jpg', 1200, 900),
            'alt' => 'Tiramisu en coupe',
        ], ['Accept' => 'application/json'])->assertCreated();

        $image = $product->images()->firstOrFail();
        $this->assertSame('Tiramisu en coupe', $image->alt);
        // jsonb PostgreSQL trie les clés : on compare sans tenir compte de l'ordre.
        $this->assertEqualsCanonicalizing(['sm', 'md', 'lg'], array_keys($image->variants));
        foreach ($image->variants as $path) {
            Storage::disk('public')->assertExists($path);
            $this->assertStringEndsWith('.webp', $path);
            // Le fichier stocké est bien un WebP (signature RIFF....WEBP), pas le JPEG d'origine.
            $bytes = Storage::disk('public')->get($path);
            $this->assertSame('RIFF', substr($bytes, 0, 4));
            $this->assertSame('WEBP', substr($bytes, 8, 4));
        }
        $this->assertSame(1200, $image->width);
        $this->assertSame(900, $image->height);
        $this->assertNotEmpty($response->json('data.url'));

        // Suppression : fichiers retirés du stockage.
        $this->deleteJson("/api/v1/admin/products/{$product->id}/images/{$image->id}")->assertNoContent();
        foreach ($image->variants as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    public function test_script_disguised_as_image_is_rejected(): void
    {
        $product = $this->variant()->product;
        $fake = UploadedFile::fake()->createWithContent('photo.jpg', "<?php system(\$_GET['c']); ?>");

        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->post("/api/v1/admin/products/{$product->id}/images", ['image' => $fake, 'alt' => 'Piège'], ['Accept' => 'application/json'])
            ->assertStatus(422);
        $this->assertSame(0, $product->images()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_too_small_image_and_unauthorized_role_are_rejected(): void
    {
        $product = $this->variant()->product;
        $this->actingAs($this->staff(User::ROLE_COMMERCIAL))->post("/api/v1/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('mini.png', 120, 120), 'alt' => 'Trop petite',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_image_dimensions');
        // Trop grande : refusée sur la foi de l'en-tête, avant tout décodage (mémoire protégée).
        $this->post("/api/v1/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('immense.png', 5200, 450), 'alt' => 'Trop grande',
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('error.code', 'invalid_image_dimensions');

        $this->actingAs($this->staff(User::ROLE_PREPARATEUR))->post("/api/v1/admin/products/{$product->id}/images", [
            'image' => UploadedFile::fake()->image('ok.jpg', 800, 800), 'alt' => 'Interdit',
        ], ['Accept' => 'application/json'])->assertForbidden();
    }
}
