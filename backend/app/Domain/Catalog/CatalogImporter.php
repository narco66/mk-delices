<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\AuditLogger;
use App\Domain\Shared\DomainException;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Import du catalogue depuis le modèle CSV (docs/modeles/catalogue-a-remplir.csv).
 * - une ligne par format ; les champs produit sont lus sur la première ligne du produit ;
 * - idempotent : mise à jour par slug (produit) et SKU (format), aucune suppression ;
 * - un produit CRÉÉ est en brouillon (jamais visible sans publication explicite) ;
 *   un produit EXISTANT conserve son statut, sauf option « publier » ;
 * - tout est validé avant écriture : une erreur annule l'import complet.
 */
class CatalogImporter
{
    private const REQUIRED = ['categorie', 'produit', 'slug', 'ingredients', 'allergenes', 'conservation', 'format', 'sku', 'prix_fcfa'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{products_created:int, products_updated:int, variants_created:int, variants_updated:int, dry_run:bool}
     */
    public function import(string $path, bool $publish = false, bool $dryRun = false): array
    {
        $products = $this->parse($path);

        $report = ['products_created' => 0, 'products_updated' => 0, 'variants_created' => 0, 'variants_updated' => 0, 'dry_run' => $dryRun];
        DB::beginTransaction();
        try {
            foreach ($products as $position => $data) {
                $category = Category::query()->withTrashed()->firstOrCreate(
                    ['slug' => Str::slug($data['categorie'])],
                    ['name' => $data['categorie'], 'active' => true, 'position' => $position],
                );
                if ($category->trashed()) {
                    $category->restore();
                }
                $product = Product::query()->withTrashed()->where('slug', $data['slug'])->first();
                $attributes = [
                    'category_id' => $category->id,
                    'name' => $data['produit'],
                    'tagline' => $data['accroche'] ?: null,
                    'description' => $data['description'] ?: null,
                    'ingredients' => $data['ingredients'],
                    'allergens' => $data['allergens'],
                    'storage_instructions' => $data['conservation'],
                    'shelf_life_hours' => $data['duree_conservation_heures'] !== '' ? (int) $data['duree_conservation_heures'] : null,
                    'requires_cold_chain' => $this->bool($data['chaine_du_froid']),
                    'is_signature' => $this->bool($data['signature']),
                    'is_featured' => $this->bool($data['mis_en_avant']),
                    'is_new' => $this->bool($data['nouveaute']),
                    'position' => $position,
                    'is_demo' => false,
                    'seo_description' => Str::limit(trim(($data['accroche'] ? $data['accroche'].'. ' : '').$data['description']), 165, '…'),
                ];
                if ($product) {
                    if ($product->trashed()) {
                        $product->restore();
                    }
                    $product->update($attributes + ($publish ? ['status' => ProductStatus::Published, 'published_at' => $product->published_at ?? now()] : []));
                    $report['products_updated']++;
                } else {
                    $product = Product::query()->create($attributes + [
                        'slug' => $data['slug'],
                        'status' => $publish ? ProductStatus::Published : ProductStatus::Draft,
                        'published_at' => $publish ? now() : null,
                    ]);
                    $report['products_created']++;
                }

                foreach ($data['variants'] as $vPosition => $v) {
                    $variant = ProductVariant::query()->withTrashed()->where('sku', $v['sku'])->first();
                    if ($variant && $variant->product_id !== $product->id) {
                        throw DomainException::unprocessable('sku_taken', "Le SKU {$v['sku']} appartient déjà à un autre produit.");
                    }
                    $variantAttributes = [
                        'label' => $v['format'],
                        'serves' => $v['parts'] !== '' ? (int) $v['parts'] : null,
                        'price_xof' => $v['prix_fcfa'],
                        'prep_minutes' => $v['delai_preparation_minutes'] !== '' ? (int) $v['delai_preparation_minutes'] : 0,
                        'max_per_order' => $v['quantite_max_par_commande'] !== '' ? (int) $v['quantite_max_par_commande'] : null,
                        'available' => true,
                        'position' => $vPosition,
                    ];
                    if ($variant) {
                        if ($variant->trashed()) {
                            $variant->restore();
                        }
                        if ($variant->price_xof !== $v['prix_fcfa']) {
                            $variant->version++;
                        }
                        $variant->fill($variantAttributes)->save();
                        $report['variants_updated']++;
                    } else {
                        $product->variants()->create($variantAttributes + ['sku' => $v['sku']]);
                        $report['variants_created']++;
                    }
                }
            }
            if ($dryRun) {
                DB::rollBack();
            } else {
                $this->audit->log('catalog.imported', null, null, $report + ['file' => basename($path)]);
                DB::commit();
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return $report;
    }

    /** @return list<array<string, mixed>> */
    private function parse(string $path): array
    {
        if (! is_readable($path)) {
            throw DomainException::unprocessable('file_not_found', "Fichier introuvable : {$path}");
        }
        $handle = fopen($path, 'r');
        $first = fgets($handle);
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $headers = array_map(fn ($h) => mb_strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $h))), str_getcsv($first, $delimiter, '"', ''));
        $missing = array_diff(self::REQUIRED, $headers);
        if ($missing !== []) {
            throw DomainException::unprocessable('invalid_header', 'Colonnes manquantes : '.implode(', ', $missing));
        }

        $products = [];
        $errors = [];
        $skus = [];
        $line = 1;
        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $line++;
            if (count(array_filter($row, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $r = array_combine($headers, array_pad(array_map('trim', $row), count($headers), ''));
            $slug = $r['slug'] ?: Str::slug($r['produit']);
            if (! preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
                $errors[] = "Ligne {$line} : slug invalide « {$slug} »";
            }
            if (! isset($products[$slug])) {
                foreach (['categorie', 'produit', 'ingredients', 'allergenes', 'conservation'] as $field) {
                    if ($r[$field] === '') {
                        $errors[] = "Ligne {$line} : « {$field} » obligatoire sur la première ligne du produit {$slug}";
                    }
                }
                $products[$slug] = $r + [
                    'slug' => $slug,
                    'allergens' => array_values(array_unique(array_filter(array_map(fn ($a) => mb_strtolower(trim($a)), explode(',', $r['allergenes']))))),
                    'variants' => [],
                ];
            }
            $price = str_replace([' ', "\u{202F}", "\u{00A0}"], '', $r['prix_fcfa']);
            if (! ctype_digit($price) || (int) $price <= 0) {
                $errors[] = "Ligne {$line} : prix invalide « {$r['prix_fcfa']} » (entier en FCFA attendu)";
            }
            $sku = strtoupper($r['sku']);
            if (! preg_match('/^[A-Z0-9\-_]{2,60}$/', $sku)) {
                $errors[] = "Ligne {$line} : SKU invalide « {$r['sku']} »";
            } elseif (isset($skus[$sku])) {
                $errors[] = "Ligne {$line} : SKU « {$sku} » en double (ligne {$skus[$sku]})";
            }
            $skus[$sku] = $line;
            if ($r['format'] === '') {
                $errors[] = "Ligne {$line} : format obligatoire";
            }
            $products[$slug]['variants'][] = ['sku' => $sku, 'prix_fcfa' => (int) $price] + $r;
        }
        fclose($handle);

        if ($products === []) {
            $errors[] = 'Aucun produit trouvé dans le fichier.';
        }
        if ($errors !== []) {
            throw DomainException::unprocessable('invalid_catalog', "Import refusé :\n- ".implode("\n- ", $errors), ['errors' => $errors]);
        }

        return array_values($products);
    }

    private function bool(?string $value): bool
    {
        return in_array(mb_strtolower(trim((string) $value)), ['oui', 'o', 'yes', 'y', '1', 'true', 'vrai', 'x'], true);
    }
}
