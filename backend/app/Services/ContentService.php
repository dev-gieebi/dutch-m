<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\ReviewStatus;
use App\Models\Banner;
use App\Models\Customer;
use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\Review;
use App\Support\AdminPrincipal;
use App\Support\Html;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Pages, FAQ, bannières et avis clients. */
class ContentService
{
    public function __construct(private readonly AuditService $audit) {}

    private static function fill(object $model, array $dto, array $except = []): void
    {
        foreach ($dto as $k => $v) {
            if (! in_array($k, $except, true)) {
                $model->setAttribute(Str::snake($k), $v);
            }
        }
    }

    public function publicPage(string $slug): array
    {
        $p = Page::where('slug', $slug)->where('is_published', true)->first() ?? throw new HttpException(404, 'Page introuvable');

        return ['slug' => $p->slug, 'title' => $p->title, 'content' => $p->content, 'seoTitle' => $p->seo_title, 'seoDescription' => $p->seo_description];
    }

    public function publicFaq(): array
    {
        return FaqCategory::with(['faqs' => fn ($q) => $q->orderBy('sort_order')])->orderBy('sort_order')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'faqs' => $c->faqs->filter(fn ($f) => $f->is_active)
                ->map(fn ($f) => ['id' => $f->id, 'question' => $f->question, 'answer' => $f->answer])->values()->all()])
            ->filter(fn ($c) => count($c['faqs']) > 0)->values()->all();
    }

    public function publicBanners(): array
    {
        return Banner::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', DB::raw('NOW()')))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', DB::raw('NOW()')))
            ->orderBy('sort_order')->get()
            ->map(fn ($b) => [
                'id' => $b->id, 'title' => $b->title, 'subtitle' => $b->subtitle, 'imageDesktopUrl' => $b->image_desktop_url,
                'imageMobileUrl' => $b->image_mobile_url, 'linkUrl' => $b->link_url, 'ctaLabel' => $b->cta_label,
            ])->all();
    }

    public function listPages(): array
    {
        return Page::orderBy('slug')->get()->toArray();
    }

    public function getPage(int $id): array
    {
        return (Page::find($id) ?? throw new HttpException(404, 'Page introuvable'))->toArray();
    }

    public function savePage(?int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $p = $id ? (Page::find($id) ?? throw new HttpException(404, 'Page introuvable')) : new Page;
        $before = $id ? $p->attributesToArrayCamel() : null;
        self::fill($p, $dto, ['content']);
        if (array_key_exists('content', $dto)) {
            $p->content = Html::sanitize($dto['content']) ?? '';
        }
        $p->save();
        $this->audit->log($actor->id, $id ? 'page.update' : 'page.create', 'page', $p->id, $before, $dto, $meta);

        return $p->refresh()->toArray();
    }

    public function removePage(int $id): array
    {
        Page::whereKey($id)->delete();

        return ['success' => true];
    }

    public function listFaqAdmin(): array
    {
        return FaqCategory::with(['faqs' => fn ($q) => $q->orderBy('sort_order')])->orderBy('sort_order')->get()->toArray();
    }

    public function saveFaqCategory(?int $id, array $dto): array
    {
        $c = $id ? (FaqCategory::find($id) ?? throw new HttpException(404, 'Catégorie introuvable')) : new FaqCategory;
        self::fill($c, $dto);
        $c->save();

        return $c->refresh()->toArray();
    }

    public function removeFaqCategory(int $id): array
    {
        FaqCategory::whereKey($id)->delete();

        return ['success' => true];
    }

    public function saveFaq(?int $id, array $dto): array
    {
        $f = $id ? (Faq::find($id) ?? throw new HttpException(404, 'Question introuvable')) : new Faq;
        self::fill($f, $dto, ['answer']);
        if (array_key_exists('answer', $dto)) {
            $f->answer = Html::sanitize($dto['answer']) ?? '';
        }
        $f->save();

        return $f->refresh()->toArray();
    }

    public function removeFaq(int $id): array
    {
        Faq::whereKey($id)->delete();

        return ['success' => true];
    }

    public function listBanners(): array
    {
        return Banner::orderBy('sort_order')->get()->toArray();
    }

    public function saveBanner(?int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $b = $id ? (Banner::find($id) ?? throw new HttpException(404, 'Bannière introuvable')) : new Banner;
        self::fill($b, $dto, ['startsAt', 'endsAt']);
        foreach (['startsAt' => 'starts_at', 'endsAt' => 'ends_at'] as $k => $col) {
            if (array_key_exists($k, $dto)) {
                $b->{$col} = $dto[$k] ? Carbon::parse($dto[$k]) : null;
            }
        }
        $b->save();
        $this->audit->log($actor->id, $id ? 'banner.update' : 'banner.create', 'banner', $b->id, null, $dto, $meta);

        return $b->refresh()->toArray();
    }

    public function removeBanner(int $id): array
    {
        Banner::whereKey($id)->delete();

        return ['success' => true];
    }

    /** Avis : réservé aux clients ayant reçu le produit. */
    public function createReview(int $customerId, array $dto): array
    {
        $customer = Customer::findOrFail($customerId);
        $order = Order::where('customer_id', $customerId)
            ->whereIn('status', [OrderStatus::DELIVERED->value, OrderStatus::PARTIALLY_DELIVERED->value])
            ->whereHas('items', fn ($q) => $q->where('product_id', $dto['productId']))->first()
            ?? throw new HttpException(400, 'Vous pourrez donner votre avis après réception de ce produit');
        if (Review::where('product_id', $dto['productId'])->where('customer_id', $customerId)->exists()) {
            throw new HttpException(400, 'Vous avez déjà donné votre avis sur ce produit');
        }
        if (! Product::whereKey($dto['productId'])->exists()) {
            throw new HttpException(404, 'Produit introuvable');
        }
        $parts = explode(' ', $customer->full_name);
        Review::create([
            'product_id' => $dto['productId'],
            'customer_id' => $customerId,
            'order_id' => $order->id,
            'author_name' => trim($parts[0].' '.(isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '').'.'),
            'rating' => $dto['rating'],
            'comment' => trim($dto['comment'] ?? '') ?: null,
            'status' => ReviewStatus::PENDING->value,
        ]);

        return ['message' => 'Merci ! Votre avis sera publié après modération.'];
    }

    public function listReviews(array $q): array
    {
        $qb = Review::with('product')->when(! empty($q['status']), fn ($w) => $w->where('status', $q['status']));
        $total = (clone $qb)->count();
        $rows = $qb->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(fn ($r) => $r->attributesToArrayCamel() + [
            'product' => $r->product ? ['id' => $r->product->id, 'name' => $r->product->name] : null,
        ])->all(), $total, $q);
    }

    public function moderate(int $id, string $status, AdminPrincipal $actor, RequestMeta $meta): array
    {
        Review::whereKey($id)->update(['status' => $status]);
        $this->audit->log($actor->id, 'review.moderate', 'review', $id, null, ['status' => $status], $meta);

        return ['success' => true];
    }
}
