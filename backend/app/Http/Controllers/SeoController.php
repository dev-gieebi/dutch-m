<?php

namespace App\Http\Controllers;

use App\Enums\ProductStatus;
use App\Services\UploadService;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** sitemap.xml, robots.txt et fichiers téléversés (/uploads). */
class SeoController extends Controller
{
    /** Pages fixes de la boutique (les pages de contenu viennent de la table pages). */
    private const STATIC_PATHS = ['/' => ['1.0', 'daily'], '/boutique' => ['0.9', 'daily'], '/nouveautes' => ['0.8', 'daily'],
        '/promotions' => ['0.8', 'daily'], '/faq' => ['0.5', 'monthly'], '/contact' => ['0.5', 'yearly'], '/suivi' => ['0.3', 'yearly']];

    public function sitemap(): Response
    {
        $base = config('amoria.web_public_url');
        $x = fn (string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $abs = fn (string $u) => preg_match('#^https?://#', $u) ? $u : $base.'/'.ltrim($u, '/');
        $url = function (string $loc, $lastmod, string $priority, string $freq, array $images = []) use ($base, $x, $abs) {
            $imgs = implode('', array_map(fn ($i) => '<image:image><image:loc>'.$x($abs($i['url'])).'</image:loc>'
                .($i['title'] ? '<image:title>'.$x($i['title']).'</image:title>' : '').'</image:image>', $images));

            return '<url><loc>'.$x($base.$loc).'</loc>'.($lastmod ? '<lastmod>'.Carbon::parse($lastmod)->toAtomString().'</lastmod>' : '')
                ."<changefreq>{$freq}</changefreq><priority>{$priority}</priority>{$imgs}</url>";
        };

        $parts = [];
        foreach (self::STATIC_PATHS as $path => [$priority, $freq]) {
            $parts[] = $url($path, null, $priority, $freq);
        }
        foreach (DB::select('SELECT slug, updated_at FROM pages WHERE is_published = 1') as $p) {
            $parts[] = $url($p->slug === 'a-propos' ? '/a-propos' : "/pages/{$p->slug}", $p->updated_at, '0.4', 'monthly');
        }
        foreach (DB::select('SELECT slug, updated_at FROM categories WHERE is_active = 1 AND deleted_at IS NULL') as $c) {
            $parts[] = $url("/categorie/{$c->slug}", $c->updated_at, '0.7', 'weekly');
        }
        foreach (DB::select('SELECT slug, updated_at FROM collections WHERE is_active = 1 AND (starts_at IS NULL OR starts_at <= NOW()) AND (ends_at IS NULL OR ends_at >= NOW())') as $c) {
            $parts[] = $url("/collection/{$c->slug}", $c->updated_at, '0.7', 'weekly');
        }
        $products = DB::select('SELECT id, slug, name, updated_at FROM products WHERE status = ? AND deleted_at IS NULL', [ProductStatus::PUBLISHED->value]);
        $images = collect(DB::select('SELECT product_id, url, alt FROM product_images ORDER BY is_main DESC, sort_order ASC'))->groupBy('product_id');
        foreach ($products as $p) {
            $imgs = ($images[$p->id] ?? collect())->take(5)->map(fn ($i) => ['url' => $i->url, 'title' => $i->alt ?: $p->name])->all();
            $parts[] = $url("/produit/{$p->slug}", $p->updated_at, '0.8', 'weekly', $imgs);
        }

        return response('<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">'
            .implode('', $parts).'</urlset>', 200, [
                'Content-Type' => 'application/xml; charset=utf-8',
                'Cache-Control' => 'public, max-age=3600',
            ]);
    }

    public function robots(): Response
    {
        $base = config('amoria.web_public_url');
        $lines = [
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin',
            'Disallow: /api/',
            'Disallow: /compte',
            'Disallow: /mon-compte',
            'Disallow: /panier',
            'Disallow: /commande',
            'Disallow: /paiement',
            'Disallow: /suivi/',
            'Disallow: /recherche',
            'Allow: /uploads/',
            '',
            "Sitemap: {$base}/sitemap.xml",
        ];

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /** Sert les fichiers téléversés (cache long, noms aléatoires immuables). */
    public function upload(string $path): BinaryFileResponse
    {
        $root = realpath(UploadService::root());
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;
        if (! $file || ! str_starts_with($file, $root) || ! is_file($file)) {
            abort(404);
        }

        return response()->file($file, [
            'Cache-Control' => 'public, max-age=2592000, immutable',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
