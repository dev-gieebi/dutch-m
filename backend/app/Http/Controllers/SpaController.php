<?php

namespace App\Http\Controllers;

use App\Services\SeoService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sert l'application React compilée (frontWeb → public/dist) :
 *  - les fichiers du build (assets, favicon, logo…) avec un cache long ;
 *  - pour toute autre URL, l'index.html enrichi des métadonnées SEO de la page demandée.
 */
class SpaController extends Controller
{
    public static function distPath(string $file = ''): string
    {
        return public_path('dist'.($file !== '' ? DIRECTORY_SEPARATOR.$file : ''));
    }

    public function __invoke(Request $request, SeoService $seo): Response
    {
        if ($request->is('api/*', 'broadcasting/*')) {
            throw new NotFoundHttpException;
        }
        $path = trim(rawurldecode($request->path()), '/');

        if ($path !== '' && $path !== 'index.html' && ($file = $this->staticFile($path))) {
            return $file;
        }

        // Modèle HTML défini dans routes/web.php (dist/index.html, relatif au dossier public).
        $template = public_path($request->route('template') ?? 'dist/index.html');
        if (! is_file($template)) {
            return response('Le front n’est pas compilé : lancez « npm run build » dans frontWeb (sortie : backend/public/dist).', 503)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $meta = $seo->forPath($path === '/' ? '' : $path);

        return response($this->render(file_get_contents($template), $meta), $meta['status'], [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'X-Robots-Tag' => $meta['robots'],
            'Content-Language' => 'fr',
        ]);
    }

    /** Fichier existant du build (protégé contre la remontée de répertoires). */
    private function staticFile(string $path): ?BinaryFileResponse
    {
        $root = realpath(self::distPath());
        $file = $root ? realpath($root.DIRECTORY_SEPARATOR.$path) : false;
        if (! $file || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR) || ! is_file($file)) {
            return null;
        }
        // Les fichiers de /assets ont un nom versionné par Vite : cache immuable d'un an.
        $immutable = str_starts_with($path, 'assets/');

        return response()->file($file, [
            'Cache-Control' => $immutable ? 'public, max-age=31536000, immutable' : 'public, max-age=86400',
        ]);
    }

    private function render(string $html, array $meta): string
    {
        $e = fn (?string $v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Balises également gérées par le composant <Seo> du front (react-helmet-async les remplace grâce à data-rh).
        $tags = [
            '<meta name="description" content="'.$e($meta['description']).'" data-rh="true" />',
            '<link rel="canonical" href="'.$e($meta['canonical']).'" data-rh="true" />',
            '<meta name="robots" content="'.$e($meta['robots']).'" data-rh="true" />',
            '<meta property="og:title" content="'.$e($meta['title']).'" data-rh="true" />',
            '<meta property="og:description" content="'.$e($meta['description']).'" data-rh="true" />',
            '<meta property="og:type" content="'.$e($meta['type']).'" data-rh="true" />',
            '<meta property="og:url" content="'.$e($meta['canonical']).'" data-rh="true" />',
            '<meta property="og:locale" content="fr_FR" data-rh="true" />',
            '<meta name="twitter:card" content="'.($meta['image'] ? 'summary_large_image' : 'summary').'" data-rh="true" />',
        ];
        if ($meta['image']) {
            $tags[] = '<meta property="og:image" content="'.$e($meta['image']).'" data-rh="true" />';
        }
        // Balises propres au rendu serveur : retirées par le front dès la première navigation (data-ssr).
        $tags[] = '<meta property="og:site_name" content="'.$e($meta['siteName']).'" data-ssr="true" />';
        $tags[] = '<meta name="twitter:title" content="'.$e($meta['title']).'" data-ssr="true" />';
        $tags[] = '<meta name="twitter:description" content="'.$e($meta['description']).'" data-ssr="true" />';
        if ($meta['image']) {
            $tags[] = '<meta name="twitter:image" content="'.$e($meta['image']).'" data-ssr="true" />';
            $tags[] = '<meta property="og:image:alt" content="'.$e($meta['imageAlt']).'" data-ssr="true" />';
        }
        foreach ($meta['extraMeta'] as $property => $content) {
            $tags[] = '<meta property="'.$e($property).'" content="'.$e($content).'" data-ssr="true" />';
        }
        foreach ($meta['jsonLd'] as $ld) {
            $json = json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
            $tags[] = '<script type="application/ld+json" data-ssr="true">'.$json.'</script>';
        }

        $html = preg_replace('#<title>.*?</title>#s', '<title>'.$e($meta['title']).'</title>', $html, 1);
        $html = preg_replace('#\s*<!-- Modèle brut[^>]*-->#u', '', $html);
        $html = preg_replace('#\s*<meta name="(description|robots)"[^>]*>#', '', $html);
        $html = str_replace('</head>', '    '.implode("\n    ", $tags)."\n  </head>", $html);

        // Contenu de secours lisible sans JavaScript (remplacé par l'application au démarrage).
        $noscript = '<noscript><h1>'.$e($meta['heading']).'</h1><p>'.$e($meta['description']).'</p>'
            .'<p><a href="/">Accueil</a> · <a href="/boutique">Boutique</a> · <a href="/nouveautes">Nouveautés</a> · '
            .'<a href="/promotions">Promotions</a> · <a href="/faq">FAQ</a> · <a href="/contact">Contact</a></p>'
            .'<p>Activez JavaScript pour profiter de la boutique.</p></noscript>';

        return str_replace('<div id="root"></div>', '<div id="root"></div>'.$noscript, $html);
    }
}
