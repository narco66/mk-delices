<?php

namespace App\Http\Controllers;

use App\Models\ContentPage;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Pré-rendu SEO léger du SPA (NFR-009) : le HTML initial servi aux robots et aux réseaux sociaux
 * contient déjà titre, description, URL canonique, Open Graph et données structurées Product.
 * L'application React prend ensuite le relais côté navigateur.
 *
 * En production, Nginx route les pages publiques vers ce contrôleur (voir docs/11-DEPLOYMENT et DEV-ARCHITECTURE).
 */
class SpaController extends Controller
{
    public function __invoke(Request $request, ?string $path = null): Response
    {
        $indexPath = config('mkdelices.spa_index_path');
        if (! $indexPath || ! is_file($indexPath)) {
            abort(404, 'Build du frontend introuvable (SPA_INDEX_PATH).');
        }
        $html = file_get_contents($indexPath);
        $meta = $this->metaFor('/'.ltrim((string) $path, '/'));

        return response($this->inject($html, $meta), $meta['status'] ?? 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function sitemap(): Response
    {
        $xml = Cache::remember('mk:sitemap', 3600, function () {
            $base = rtrim(config('mkdelices.frontend_url'), '/');
            $urls = ['/', '/nos-tiramisus', '/boutique', '/promotions', '/livraison', '/notre-histoire', '/contact', '/faq'];
            $entries = array_map(fn ($u) => ['loc' => $base.$u, 'lastmod' => null], $urls);
            foreach (Product::query()->publiclyVisible()->get(['slug', 'updated_at']) as $p) {
                $entries[] = ['loc' => "{$base}/produits/{$p->slug}", 'lastmod' => $p->updated_at?->toDateString()];
            }
            $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach ($entries as $e) {
                $body .= '<url><loc>'.e($e['loc']).'</loc>'.($e['lastmod'] ? '<lastmod>'.$e['lastmod'].'</lastmod>' : '').'</url>';
            }

            return $body.'</urlset>';
        });

        return response($xml)->header('Content-Type', 'application/xml');
    }

    public function robots(): Response
    {
        $base = rtrim(config('mkdelices.frontend_url'), '/');
        $body = app()->isProduction()
            ? "User-agent: *\nDisallow: /admin\nDisallow: /livreur\nDisallow: /compte\nDisallow: /commander\nDisallow: /paiement\nDisallow: /suivi\nSitemap: {$base}/sitemap.xml\n"
            : "User-agent: *\nDisallow: /\n"; // Environnements hors production : jamais indexés.

        return response($body)->header('Content-Type', 'text/plain');
    }

    /** @return array{title:string, description:string, canonical:string, image?:?string, jsonld?:array, robots?:string, status?:int} */
    private function metaFor(string $path): array
    {
        $base = rtrim(config('mkdelices.frontend_url'), '/');
        $default = [
            'title' => 'MK Délices — Tiramisus et pâtisseries artisanales à Dakar',
            'description' => 'Tiramisus, gâteaux et douceurs faits main à Dakar. Commandez en ligne ou par WhatsApp, livraison au créneau de votre choix.',
            'canonical' => $base.$path,
        ];

        if (preg_match('#^/produits/([a-z0-9-]+)$#', $path, $m)) {
            $product = Product::query()->publiclyVisible()->with(['variants', 'images'])->where('slug', $m[1])->first();
            if (! $product) {
                return $default + ['robots' => 'noindex', 'status' => 404];
            }
            $image = $product->images->first()?->url('lg');

            return [
                'title' => ($product->seo_title ?: $product->name).' · MK Délices',
                'description' => $product->seo_description ?: ($product->tagline ?: $default['description']),
                'canonical' => $base.$path,
                'image' => $image,
                'jsonld' => [
                    '@context' => 'https://schema.org',
                    '@type' => 'Product',
                    'name' => $product->name,
                    'description' => $product->seo_description ?: $product->tagline,
                    'image' => $image ? [$image] : null,
                    'brand' => ['@type' => 'Brand', 'name' => 'MK Délices'],
                    'offers' => $product->variants->map(fn ($v) => [
                        '@type' => 'Offer', 'sku' => $v->sku, 'name' => $v->label, 'price' => $v->price_xof, 'priceCurrency' => 'XOF',
                        'availability' => $v->available && ($v->stock_qty === null || $v->stock_qty > 0) ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                    ])->values()->all(),
                ],
            ];
        }

        $pages = ['/notre-histoire' => 'notre-histoire', '/livraison' => 'livraison', '/contact' => 'contact', '/conditions-generales' => 'conditions-generales', '/confidentialite' => 'confidentialite', '/mentions-legales' => 'mentions-legales'];
        if (isset($pages[$path]) && ($page = ContentPage::query()->where('slug', $pages[$path])->where('status', 'published')->first())) {
            return ['title' => ($page->seo_title ?: $page->title).' · MK Délices', 'description' => $page->seo_description ?: ($page->excerpt ?? $default['description']), 'canonical' => $base.$path];
        }

        $static = [
            '/nos-tiramisus' => ['Nos tiramisus artisanaux · MK Délices', 'Le tiramisu, spécialité de MK Délices : en coupe individuelle ou à partager, livré à Dakar.'],
            '/boutique' => ['Boutique · MK Délices', 'Tout le catalogue MK Délices : tiramisus, gâteaux, bonbons et desserts artisanaux livrés à Dakar.'],
            '/faq' => ['Questions fréquentes · MK Délices', 'Commande, paiement, livraison, conservation et allergènes : toutes les réponses.'],
            '/promotions' => ['Promotions · MK Délices', 'Les offres du moment chez MK Délices.'],
        ];
        if (isset($static[$path])) {
            return ['title' => $static[$path][0], 'description' => $static[$path][1], 'canonical' => $base.$path];
        }
        $private = '#^/(admin|livreur|compte|commander|panier|paiement|suivi|commande|connexion|inscription)#';

        return $default + (preg_match($private, $path) ? ['robots' => 'noindex, nofollow'] : []);
    }

    private function inject(string $html, array $meta): string
    {
        $tags = '<title>'.e($meta['title']).'</title>'
            .'<meta name="description" content="'.e($meta['description']).'">'
            .'<link rel="canonical" href="'.e($meta['canonical']).'">'
            .'<meta property="og:title" content="'.e($meta['title']).'">'
            .'<meta property="og:description" content="'.e($meta['description']).'">'
            .'<meta property="og:url" content="'.e($meta['canonical']).'">'
            .(! empty($meta['image']) ? '<meta property="og:image" content="'.e($meta['image']).'">' : '')
            .(! empty($meta['robots']) ? '<meta name="robots" content="'.e($meta['robots']).'">' : '')
            .(! empty($meta['jsonld']) ? '<script type="application/ld+json">'.json_encode(array_filter($meta['jsonld'], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).'</script>' : '');

        $html = preg_replace('#<title>.*?</title>#s', '', $html) ?? $html;
        $html = preg_replace('#<meta name="description"[^>]*>#', '', $html) ?? $html;

        return str_replace('</head>', $tags.'</head>', $html);
    }
}
