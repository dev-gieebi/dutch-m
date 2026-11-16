<?php

namespace App\Services;

use App\Enums\AvailabilityMode;
use App\Services\Catalog\CatalogPublicService;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Métadonnées SEO calculées côté serveur pour chaque URL de la boutique
 * (titre, description, canonique, robots, Open Graph, Twitter, JSON-LD schema.org).
 * Elles sont injectées dans le HTML de l'application React avant envoi, pour les robots
 * qui n'exécutent pas JavaScript (aperçus WhatsApp / Facebook, premier passage de Google).
 */
class SeoService
{
    /** Pages privées ou sans intérêt pour les moteurs : jamais indexées. */
    private const PRIVATE_PATTERNS = [
        '#^admin(/|$)#', '#^panier$#', '#^commande(/|$)#', '#^paiement/#', '#^suivi/.+#',
        '#^compte(/|$)#', '#^mon-compte(/|$)#', '#^recherche$#',
    ];

    /** Routes connues de l'application React (les autres renvoient une 404). */
    private const KNOWN_PATTERNS = [
        '#^$#', '#^boutique$#', '#^categorie/[^/]+$#', '#^collection/[^/]+$#', '#^nouveautes$#', '#^promotions$#',
        '#^recherche$#', '#^produit/[^/]+$#', '#^panier$#', '#^commande$#', '#^commande/confirmation/[^/]+$#',
        '#^paiement/[^/]+$#', '#^suivi(/[^/]+)?$#', '#^a-propos$#', '#^pages/[^/]+$#', '#^faq$#', '#^contact$#',
        '#^compte(/.*)?$#', '#^mon-compte(/.*)?$#', '#^admin(/.*)?$#',
    ];

    public function __construct(
        private readonly SettingsService $settings,
        private readonly CatalogPublicService $catalog,
        private readonly ContentService $content,
    ) {}

    public function baseUrl(): string
    {
        return config('amoria.web_public_url');
    }

    public function absolute(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        return preg_match('#^https?://#', $url) ? $url : $this->baseUrl().'/'.ltrim($url, '/');
    }

    private static function excerpt(?string $html, int $max = 160): ?string
    {
        if (! $html) {
            return null;
        }
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $max ? rtrim(mb_substr($text, 0, $max - 1)).'…' : $text;
    }

    /**
     * @return array{status: int, title: string, description: ?string, canonical: string, robots: string, type: string,
     *               image: ?string, imageAlt: ?string, extraMeta: array<string, string>, jsonLd: list<array>, heading: string, siteName: string}
     */
    public function forPath(string $path): array
    {
        $path = trim($path, '/');
        $shop = $this->settings->get('shop');
        $default = [
            'status' => 200,
            'title' => "{$shop['name']} – {$shop['tagline']}",
            'description' => 'Fleurs artificielles, fleurs vintage DutCh’M Héritage, compositions florales, vases, bougies et coffrets bien-être. Livraison à Libreville.',
            'canonical' => $this->baseUrl().($path === '' ? '/' : "/{$path}"),
            'robots' => 'index, follow, max-image-preview:large',
            'type' => 'website',
            'image' => $this->absolute($shop['logoUrl'] ?: '/logo.png'),
            'imageAlt' => $shop['name'],
            'extraMeta' => [],
            'jsonLd' => [],
            'heading' => $shop['name'],
            'siteName' => $shop['name'],
        ];

        $known = (bool) array_filter(self::KNOWN_PATTERNS, fn ($p) => preg_match($p, $path));
        if (! $known) {
            return $this->notFound($default);
        }
        if (array_filter(self::PRIVATE_PATTERNS, fn ($p) => preg_match($p, $path))) {
            return ['robots' => 'noindex, nofollow', 'jsonLd' => []] + $default;
        }

        try {
            $meta = match (true) {
                $path === '' => $this->home($default),
                (bool) preg_match('#^produit/([^/]+)$#', $path, $m) => $this->product($default, $m[1]),
                (bool) preg_match('#^categorie/([^/]+)$#', $path, $m) => $this->category($default, $m[1]),
                (bool) preg_match('#^collection/([^/]+)$#', $path, $m) => $this->collection($default, $m[1]),
                (bool) preg_match('#^pages/([^/]+)$#', $path, $m) => $this->page($default, $m[1]),
                $path === 'a-propos' => $this->page($default, 'a-propos'),
                $path === 'faq' => $this->faq($default),
                default => $this->staticPage($default, $path),
            };
        } catch (HttpException $e) {
            if ($e->getStatusCode() === 404) {
                return $this->notFound($default);
            }
            throw $e;
        }

        return $meta;
    }

    private function notFound(array $d): array
    {
        return ['status' => 404, 'title' => 'Page introuvable | '.$this->settings->get('shop')['name'], 'robots' => 'noindex, follow',
            'description' => 'La page demandée n’existe pas ou plus.', 'heading' => 'Page introuvable', 'jsonLd' => []] + $d;
    }

    private function title(string $title): string
    {
        $name = $this->settings->get('shop')['name'];

        return str_contains($title, $name) ? $title : "{$title} | {$name}";
    }

    /** @param list<array{name: string, path: string}> $items */
    private function breadcrumb(array $items): array
    {
        $list = [['name' => 'Accueil', 'path' => '/'], ...$items];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(fn ($it, $i) => [
                '@type' => 'ListItem', 'position' => $i + 1, 'name' => $it['name'], 'item' => $this->baseUrl().$it['path'],
            ], $list, array_keys($list)),
        ];
    }

    /** Fiche « Florist » (LocalBusiness) + site avec moteur de recherche interne. */
    private function home(array $d): array
    {
        $shop = $this->settings->get('shop');
        $support = $this->settings->get('supportHours');
        $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        $sameAs = array_values(array_filter($shop['socials'] ?? []));
        $business = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Florist',
            '@id' => $this->baseUrl().'/#boutique',
            'name' => $shop['name'],
            'description' => $shop['tagline'],
            'url' => $this->baseUrl().'/',
            'logo' => $this->absolute($shop['logoUrl'] ?: '/logo.png'),
            'image' => $this->absolute($shop['logoUrl'] ?: '/logo.png'),
            'telephone' => $shop['phone'],
            'email' => $shop['email'],
            'currenciesAccepted' => 'XAF',
            'paymentAccepted' => 'Espèces, Airtel Money, Moov Money',
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $shop['address'], 'addressLocality' => $shop['city'], 'addressCountry' => 'GA'],
            'areaServed' => ['@type' => 'City', 'name' => $shop['city']],
            'openingHoursSpecification' => array_map(fn ($s) => [
                '@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days[$s['day']] ?? 'Monday', 'opens' => $s['open'], 'closes' => $s['close'],
            ], $support['slots'] ?? []),
            'sameAs' => $sameAs ?: null,
        ]);
        $website = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $shop['name'],
            'url' => $this->baseUrl().'/',
            'inLanguage' => 'fr',
            'publisher' => ['@id' => $this->baseUrl().'/#boutique'],
            'potentialAction' => [
                '@type' => 'SearchAction',
                'target' => ['@type' => 'EntryPoint', 'urlTemplate' => $this->baseUrl().'/recherche?q={search_term_string}'],
                'query-input' => 'required name=search_term_string',
            ],
        ];

        return ['jsonLd' => [$business, $website]] + $d;
    }

    private function product(array $d, string $slug): array
    {
        $p = $this->catalog->productBySlug($slug);
        $title = $this->title($p['seoTitle'] ?: $p['name']);
        $description = $p['seoDescription'] ?: ($p['shortDescription'] ?: self::excerpt($p['description'])) ?: $d['description'];
        $images = array_values(array_filter(array_map(fn ($i) => $this->absolute($i['url']), $p['images'])));
        $url = $this->baseUrl()."/produit/{$p['slug']}";
        $availability = fn (string $a) => match ($a) {
            AvailabilityMode::IN_STOCK->value => 'https://schema.org/InStock',
            AvailabilityMode::PREORDER->value => 'https://schema.org/PreOrder',
            default => 'https://schema.org/OutOfStock',
        };
        $prices = array_column($p['variants'], 'price') ?: [$p['price']];
        $offer = count($p['variants']) > 1 && min($prices) !== max($prices)
            ? ['@type' => 'AggregateOffer', 'priceCurrency' => 'XAF', 'lowPrice' => min($prices), 'highPrice' => max($prices),
                'offerCount' => count($p['variants']), 'availability' => $availability($p['availability']), 'url' => $url]
            : ['@type' => 'Offer', 'priceCurrency' => 'XAF', 'price' => $p['price'], 'availability' => $availability($p['availability']),
                'itemCondition' => 'https://schema.org/NewCondition', 'url' => $url, 'seller' => ['@type' => 'Organization', 'name' => $this->settings->get('shop')['name']]];
        $product = array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $p['name'],
            'description' => self::excerpt($p['description'], 5000) ?? $description,
            'sku' => $p['reference'],
            'image' => $images ?: null,
            'brand' => ['@type' => 'Brand', 'name' => $this->settings->get('shop')['name']],
            'category' => $p['category']['name'] ?? null,
            'material' => $p['material'] ?: null,
            'color' => $p['colors'] ? implode(', ', $p['colors']) : null,
            'url' => $url,
            'offers' => $offer,
            'aggregateRating' => ($p['rating']['count'] ?? 0) > 0
                ? ['@type' => 'AggregateRating', 'ratingValue' => $p['rating']['average'], 'reviewCount' => $p['rating']['count'], 'bestRating' => 5, 'worstRating' => 1]
                : null,
        ]);
        $crumbs = array_map(fn ($c) => ['name' => $c['name'], 'path' => "/categorie/{$c['slug']}"], $p['breadcrumbs']);
        $crumbs[] = ['name' => $p['name'], 'path' => "/produit/{$p['slug']}"];

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $url,
            'type' => 'product',
            'image' => $images[0] ?? $d['image'],
            'imageAlt' => $p['images'][0]['alt'] ?? $p['name'],
            'extraMeta' => ['product:price:amount' => (string) $p['price'], 'product:price:currency' => 'XAF',
                'product:availability' => $p['availability'] === 'IN_STOCK' ? 'in stock' : ($p['availability'] === 'PREORDER' ? 'preorder' : 'out of stock')],
            'jsonLd' => [$product, $this->breadcrumb($crumbs)],
            'heading' => $p['name'],
        ] + $d;
    }

    /** Liste d'articles (ItemList) pour les pages catalogue. */
    private function itemList(array $query): array
    {
        $cards = $this->catalog->listProducts($query + ['page' => 1, 'limit' => 20])['data'];

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'numberOfItems' => count($cards),
            'itemListElement' => array_map(fn ($c, $i) => [
                '@type' => 'ListItem', 'position' => $i + 1, 'url' => $this->baseUrl()."/produit/{$c['slug']}", 'name' => $c['name'],
            ], $cards, array_keys($cards)),
        ];
    }

    private function category(array $d, string $slug): array
    {
        $c = $this->catalog->categoryBySlug($slug);
        $crumbs = array_map(fn ($b) => ['name' => $b['name'], 'path' => "/categorie/{$b['slug']}"], $c['breadcrumbs']);

        return [
            'title' => $this->title($c['seoTitle'] ?: $c['name']),
            'description' => $c['seoDescription'] ?: self::excerpt($c['description']) ?: "Découvrez notre sélection « {$c['name']} » : livraison à Libreville et précommande.",
            'canonical' => $this->baseUrl()."/categorie/{$c['slug']}",
            'image' => $this->absolute($c['imageUrl']) ?? $d['image'],
            'imageAlt' => $c['name'],
            'jsonLd' => [
                ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $c['name'], 'url' => $this->baseUrl()."/categorie/{$c['slug']}"],
                $this->breadcrumb($crumbs),
                $this->itemList(['category' => $c['slug']]),
            ],
            'heading' => $c['name'],
        ] + $d;
    }

    private function collection(array $d, string $slug): array
    {
        $c = $this->catalog->collectionBySlug($slug);

        return [
            'title' => $this->title($c['seoTitle'] ?: $c['name']),
            'description' => $c['seoDescription'] ?: self::excerpt($c['description']) ?: "Collection « {$c['name']} » DutCh’M.",
            'canonical' => $this->baseUrl()."/collection/{$c['slug']}",
            'image' => $this->absolute($c['imageUrl']) ?? $d['image'],
            'imageAlt' => $c['name'],
            'jsonLd' => [
                ['@context' => 'https://schema.org', '@type' => 'CollectionPage', 'name' => $c['name'], 'url' => $this->baseUrl()."/collection/{$c['slug']}"],
                $this->breadcrumb([['name' => $c['name'], 'path' => "/collection/{$c['slug']}"]]),
                $this->itemList(['collection' => $c['slug']]),
            ],
            'heading' => $c['name'],
        ] + $d;
    }

    private function page(array $d, string $slug): array
    {
        $p = $this->content->publicPage($slug);
        $path = $slug === 'a-propos' ? '/a-propos' : "/pages/{$slug}";

        return [
            'title' => $this->title($p['seoTitle'] ?: $p['title']),
            'description' => $p['seoDescription'] ?: self::excerpt($p['content']) ?: $d['description'],
            'canonical' => $this->baseUrl().$path,
            'type' => 'article',
            'jsonLd' => [
                ['@context' => 'https://schema.org', '@type' => $slug === 'a-propos' ? 'AboutPage' : 'WebPage', 'name' => $p['title'], 'url' => $this->baseUrl().$path, 'inLanguage' => 'fr'],
                $this->breadcrumb([['name' => $p['title'], 'path' => $path]]),
            ],
            'heading' => $p['title'],
        ] + $d;
    }

    private function faq(array $d): array
    {
        $questions = [];
        foreach ($this->content->publicFaq() as $cat) {
            foreach ($cat['faqs'] as $f) {
                $questions[] = ['@type' => 'Question', 'name' => $f['question'], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']]];
            }
        }

        return [
            'title' => $this->title('Foire aux questions'),
            'description' => 'Réponses aux questions fréquentes : livraison à Libreville, précommandes, paiements mobile money, retours.',
            'jsonLd' => array_values(array_filter([
                $questions ? ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => $questions] : null,
                $this->breadcrumb([['name' => 'Foire aux questions', 'path' => '/faq']]),
            ])),
            'heading' => 'Foire aux questions',
        ] + $d;
    }

    private function staticPage(array $d, string $path): array
    {
        $pages = [
            'boutique' => ['Boutique', 'Toute la boutique DutCh’M : fleurs éternelles, compositions florales, vases, bougies et coffrets bien-être. Livraison à Libreville.', 'CollectionPage', []],
            'nouveautes' => ['Nouveautés', 'Les dernières nouveautés DutCh’M : fleurs éternelles et objets bien-être fraîchement arrivés.', 'CollectionPage', ['isNew' => true]],
            'promotions' => ['Promotions', 'Les promotions du moment chez DutCh’M : fleurs et décoration à prix doux, livrées à Libreville.', 'CollectionPage', ['onSale' => true]],
            'contact' => ['Contact et assistance', 'Contactez l’équipe DutCh’M à Libreville : WhatsApp, téléphone, e-mail ou formulaire.', 'ContactPage', null],
            'suivi' => ['Suivi de commande', 'Suivez votre commande DutCh’M étape par étape.', 'WebPage', null],
        ];
        if (! isset($pages[$path])) {
            return $d;
        }
        [$name, $description, $type, $listQuery] = $pages[$path];
        $jsonLd = [
            ['@context' => 'https://schema.org', '@type' => $type, 'name' => $name, 'url' => $this->baseUrl()."/{$path}", 'inLanguage' => 'fr'],
            $this->breadcrumb([['name' => $name, 'path' => "/{$path}"]]),
        ];
        if ($listQuery !== null) {
            $jsonLd[] = $this->itemList($listQuery);
        }

        return ['title' => $this->title($name), 'description' => $description, 'jsonLd' => $jsonLd, 'heading' => $name] + $d;
    }
}
