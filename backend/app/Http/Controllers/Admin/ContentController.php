<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReviewStatus;
use App\Http\Controllers\Controller;
use App\Services\ContentService;
use App\Services\DashboardService;
use App\Services\ReportsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/** Back-office : pages, FAQ, bannières, avis, tableau de bord, rapports et exports. */
class ContentController extends Controller
{
    public function __construct(private readonly ContentService $content) {}

    private static function pageRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'slug' => [$req, 'string', 'max:120', 'regex:/^[a-z0-9-]+$/'],
            'title' => [$req, 'string', 'between:2,190'],
            'content' => [$req, 'string'],
            'seoTitle' => ['sometimes', 'nullable', 'string', 'max:190'],
            'seoDescription' => ['sometimes', 'nullable', 'string', 'max:320'],
            'isPublished' => ['sometimes', 'boolean'],
        ];
    }

    private static function faqRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'categoryId' => [$req, 'integer'],
            'question' => [$req, 'string', 'between:3,255'],
            'answer' => [$req, 'string', 'between:3,10000'],
            'sortOrder' => ['sometimes', 'integer'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    private static function bannerRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'title' => [$req, 'string', 'between:2,150'],
            'subtitle' => ['sometimes', 'nullable', 'string', 'max:255'],
            'imageDesktopUrl' => [$req, 'string', 'max:500'],
            'imageMobileUrl' => ['sometimes', 'nullable', 'string', 'max:500'],
            'linkUrl' => ['sometimes', 'nullable', 'string', 'max:500'],
            'ctaLabel' => ['sometimes', 'nullable', 'string', 'max:60'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'endsAt' => ['sometimes', 'nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
            'sortOrder' => ['sometimes', 'integer'],
        ];
    }

    public function pages(): array
    {
        return $this->content->listPages();
    }

    public function page(int $id): array
    {
        return $this->content->getPage($id);
    }

    public function createPage(Request $request): JsonResponse
    {
        return response()->json($this->content->savePage(null, $request->validate(self::pageRules(false)), $this->admin($request), $this->meta($request)), 201);
    }

    public function updatePage(Request $request, int $id): array
    {
        return $this->content->savePage($id, $request->validate(self::pageRules(true)), $this->admin($request), $this->meta($request));
    }

    public function removePage(int $id): array
    {
        return $this->content->removePage($id);
    }

    public function faq(): array
    {
        return $this->content->listFaqAdmin();
    }

    public function saveFaqCategory(Request $request, ?int $id = null): JsonResponse
    {
        $v = $request->validate(['name' => ['required', 'string', 'between:2,120'], 'sortOrder' => ['nullable', 'integer']]);

        return response()->json($this->content->saveFaqCategory($id, $v), $id ? 200 : 201);
    }

    public function removeFaqCategory(int $id): array
    {
        return $this->content->removeFaqCategory($id);
    }

    public function createFaq(Request $request): JsonResponse
    {
        return response()->json($this->content->saveFaq(null, $request->validate(self::faqRules(false))), 201);
    }

    public function updateFaq(Request $request, int $id): array
    {
        return $this->content->saveFaq($id, $request->validate(self::faqRules(true)));
    }

    public function removeFaq(int $id): array
    {
        return $this->content->removeFaq($id);
    }

    public function banners(): array
    {
        return $this->content->listBanners();
    }

    public function createBanner(Request $request): JsonResponse
    {
        return response()->json($this->content->saveBanner(null, $request->validate(self::bannerRules(false)), $this->admin($request), $this->meta($request)), 201);
    }

    public function updateBanner(Request $request, int $id): array
    {
        return $this->content->saveBanner($id, $request->validate(self::bannerRules(true)), $this->admin($request), $this->meta($request));
    }

    public function removeBanner(int $id): array
    {
        return $this->content->removeBanner($id);
    }

    public function reviews(Request $request): array
    {
        $v = $request->validate(['status' => ['nullable', Rule::enum(ReviewStatus::class)]]);

        return $this->content->listReviews($v + $this->pagination($request));
    }

    public function moderate(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['status' => ['required', Rule::enum(ReviewStatus::class)]]);

        return response()->json($this->content->moderate($id, $v['status'], $this->admin($request), $this->meta($request)), 201);
    }

    // ----- Tableau de bord, rapports, exports -------------------------------------------

    public function dashboard(Request $request, DashboardService $dashboard): array
    {
        $period = $request->query('period', 'today');

        return $dashboard->get(in_array($period, ['today', '7d', '30d'], true) ? $period : 'today');
    }

    public function sales(Request $request, ReportsService $reports): array
    {
        return $reports->sales($request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'groupBy' => ['nullable', 'in:day,month,product,category,paymentMethod,zone'],
        ]));
    }

    public function preorders(ReportsService $reports): array
    {
        return $reports->preorders();
    }

    public function valuation(ReportsService $reports): array
    {
        return $reports->stockValuation();
    }

    public function export(Request $request, ReportsService $reports, string $entity): Response
    {
        $q = $request->validate(['format' => ['nullable', 'in:csv,xlsx'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $format = $q['format'] ?? 'xlsx';
        $content = $reports->render($reports->table($entity, $q), $format);
        $stamp = now()->format('Y-m-d');

        return response($content, 200, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=utf-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"amoria-{$entity}-{$stamp}.{$format}\"",
        ]);
    }
}
