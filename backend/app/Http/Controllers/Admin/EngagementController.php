<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConversationStatus;
use App\Enums\CouponType;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationTemplateCode;
use App\Http\Controllers\Controller;
use App\Services\CustomersService;
use App\Services\Marketing\CouponsService;
use App\Services\Marketing\NewsletterService;
use App\Services\MessagingService;
use App\Services\Notifications\NotificationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Back-office : messagerie, clients, marketing (codes promo, newsletter) et notifications. */
class EngagementController extends Controller
{
    public function __construct(
        private readonly MessagingService $messaging,
        private readonly CustomersService $customers,
        private readonly CouponsService $coupons,
        private readonly NewsletterService $newsletter,
        private readonly NotificationsService $notifications,
    ) {}

    // ----- Messagerie ---------------------------------------------------------------

    public function conversations(Request $request): array
    {
        $v = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::enum(ConversationStatus::class)],
            'mine' => ['nullable', 'in:true,false,1,0'],
            'linked' => ['nullable', 'in:product,order'],
            'customerId' => ['nullable', 'integer'],
        ]);
        $v['mine'] = in_array($v['mine'] ?? null, ['true', '1'], true);

        return $this->messaging->list($v + $this->pagination($request), $this->admin($request));
    }

    public function unreadCount(): array
    {
        return ['unread' => $this->messaging->unreadCount()];
    }

    public function conversation(int $id): array
    {
        return $this->messaging->detail($id);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $v = $request->validate([
            'body' => ['required', 'string', 'between:1,5000'],
            'attachmentUrl' => ['nullable', 'string', 'max:500'],
            'isInternal' => ['nullable', 'boolean'],
        ]);

        return response()->json($this->messaging->reply($id, $v, $this->admin($request)), 201);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['adminUserId' => ['nullable', 'integer']]);

        return response()->json($this->messaging->assign($id, $v['adminUserId'] ?? null, $this->admin($request), $this->meta($request)), 201);
    }

    public function status(Request $request, int $id): JsonResponse
    {
        $v = $request->validate(['status' => ['required', Rule::enum(ConversationStatus::class)]]);

        return response()->json($this->messaging->setStatus($id, $v['status']), 201);
    }

    public function note(Request $request, int $id): array
    {
        $v = $request->validate(['note' => ['present', 'nullable', 'string', 'max:5000']]);

        return $this->messaging->setNote($id, (string) ($v['note'] ?? ''));
    }

    private static function quickReplyRules(): array
    {
        return ['title' => ['required', 'string', 'between:1,120'], 'body' => ['required', 'string', 'between:1,3000'], 'sortOrder' => ['nullable', 'integer']];
    }

    public function quickReplies(): array
    {
        return $this->messaging->listQuickReplies();
    }

    public function createQuickReply(Request $request): JsonResponse
    {
        return response()->json($this->messaging->saveQuickReply(null, $request->validate(self::quickReplyRules())), 201);
    }

    public function updateQuickReply(Request $request, int $id): array
    {
        return $this->messaging->saveQuickReply($id, $request->validate(self::quickReplyRules()));
    }

    public function removeQuickReply(int $id): array
    {
        return $this->messaging->removeQuickReply($id);
    }

    // ----- Clients ---------------------------------------------------------------------

    public function customers(Request $request): array
    {
        $v = $request->validate(['q' => ['nullable', 'string', 'max:120'], 'newsletter' => ['nullable', 'in:true,false,1,0']]);
        $v['newsletter'] = in_array($v['newsletter'] ?? null, ['true', '1'], true);

        return $this->customers->adminList($v + $this->pagination($request));
    }

    public function customer(int $id): array
    {
        return $this->customers->adminDetail($id);
    }

    public function updateCustomer(Request $request, int $id): array
    {
        $v = $request->validate([
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'fullName' => ['sometimes', 'string', 'between:2,120'],
            'email' => ['sometimes', 'nullable', 'email'],
            'newsletterConsent' => ['sometimes', 'boolean'],
        ]);

        return $this->customers->adminUpdate($id, $v, $this->admin($request), $this->meta($request));
    }

    public function anonymize(Request $request, int $id): JsonResponse
    {
        return response()->json($this->customers->anonymize($id, null, $this->admin($request)->id, $this->meta($request)), 201);
    }

    // ----- Marketing -------------------------------------------------------------------

    private function search(Request $request): array
    {
        return $request->validate(['q' => ['nullable', 'string', 'max:120']]) + $this->pagination($request);
    }

    public function subscribers(Request $request): array
    {
        return $this->newsletter->list($this->search($request));
    }

    public function unsubscribe(int $id): array
    {
        return $this->newsletter->unsubscribe($id);
    }

    private static function couponRules(bool $partial): array
    {
        $req = $partial ? 'sometimes' : 'required';

        return [
            'code' => [$req, 'string', 'regex:/^[A-Z0-9_-]{3,40}$/'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => [$req, Rule::enum(CouponType::class)],
            'value' => [$req, 'integer', 'min:0'],
            'minSubtotal' => ['sometimes', 'integer', 'min:0'],
            'maxDiscount' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'categoryIds' => ['sometimes', 'nullable', 'array'], 'categoryIds.*' => ['integer'],
            'productIds' => ['sometimes', 'nullable', 'array'], 'productIds.*' => ['integer'],
            'usageLimit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usageLimitPerCustomer' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'startsAt' => ['sometimes', 'nullable', 'date'],
            'endsAt' => ['sometimes', 'nullable', 'date'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }

    private const COUPON_MESSAGES = ['code.regex' => 'Code : 3 à 40 caractères MAJUSCULES, chiffres, - ou _'];

    public function coupons(Request $request): array
    {
        return $this->coupons->list($this->search($request));
    }

    public function coupon(int $id): array
    {
        return $this->coupons->get($id);
    }

    public function createCoupon(Request $request): JsonResponse
    {
        return response()->json($this->coupons->create($this->admin($request), $request->validate(self::couponRules(false), self::COUPON_MESSAGES), $this->meta($request)), 201);
    }

    public function updateCoupon(Request $request, int $id): array
    {
        return $this->coupons->update($this->admin($request), $id, $request->validate(self::couponRules(true), self::COUPON_MESSAGES), $this->meta($request));
    }

    public function removeCoupon(Request $request, int $id): array
    {
        return $this->coupons->remove($this->admin($request), $id, $this->meta($request));
    }

    // ----- Notifications ----------------------------------------------------------------

    public function notifications(Request $request): array
    {
        return $this->notifications->listAdmin($this->admin($request)->id, $this->pagination($request));
    }

    public function readAll(Request $request): JsonResponse
    {
        return response()->json($this->notifications->markAllRead($this->admin($request)->id), 201);
    }

    public function read(int $id): JsonResponse
    {
        return response()->json($this->notifications->markRead($id), 201);
    }

    public function templates(): array
    {
        return $this->notifications->listTemplates();
    }

    private static function templateTarget(string $code, string $channel): void
    {
        if (! NotificationTemplateCode::tryFrom($code) || ! NotificationChannelType::tryFrom($channel)) {
            throw new HttpException(400, 'Gabarit ou canal inconnu');
        }
    }

    private static function templateRules(): array
    {
        return ['subject' => ['nullable', 'string', 'max:255'], 'body' => ['required', 'string', 'max:60000'], 'isActive' => ['nullable', 'boolean']];
    }

    public function saveTemplate(Request $request, string $code, string $channel): array
    {
        self::templateTarget($code, $channel);

        return $this->notifications->saveTemplate($code, $channel, $request->validate(self::templateRules()));
    }

    public function previewTemplate(Request $request, string $code, string $channel): JsonResponse
    {
        self::templateTarget($code, $channel);

        return response()->json($this->notifications->preview($channel, $request->validate(self::templateRules())), 201);
    }
}
