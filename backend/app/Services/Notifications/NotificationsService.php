<?php

namespace App\Services\Notifications;

use App\Enums\NotificationChannelType;
use App\Enums\NotificationStatus;
use App\Enums\NotificationTemplateCode;
use App\Models\AdminNotification;
use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Services\Realtime;
use App\Services\SettingsService;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\Template;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpException;

class NotificationsService
{
    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly Realtime $realtime,
        private readonly Channels $channels,
    ) {}

    public static function defaults(string $code): array
    {
        return config("notification_templates.{$code}");
    }

    private static function defaultFor(string $code, string $channel): array
    {
        $def = self::defaults($code);
        if ($channel === NotificationChannelType::EMAIL->value) {
            return $def['email'];
        }

        return ['subject' => null, 'body' => $channel === NotificationChannelType::SMS->value ? $def['sms'] : $def['whatsapp']];
    }

    private function resolveTemplate(string $code, string $channel): ?array
    {
        $row = NotificationTemplate::where('code', $code)->where('channel', $channel)->first();
        if ($row && ! $row->is_active) {
            return null;
        }

        return $row ? ['subject' => $row->subject, 'body' => $row->body] : self::defaultFor($code, $channel);
    }

    private function baseData(): array
    {
        $shop = $this->settings->get('shop');

        return [
            'shopName' => $shop['name'],
            'shopTagline' => $shop['tagline'],
            'shopAddress' => $shop['address'],
            'shopEmail' => $shop['email'],
            'whatsappNumber' => Format::phone($this->settings->get('whatsappNumber')),
        ];
    }

    /** @return array{subject: ?string, body: string} */
    public function render(string $channel, array $tpl, array $data): array
    {
        $ctx = $this->baseData() + $data;
        $subject = ! empty($tpl['subject']) ? Template::render($tpl['subject'], $ctx, false) : null;
        $isEmail = $channel === NotificationChannelType::EMAIL->value;
        $content = Template::render($tpl['body'], $ctx, $isEmail);
        if (! $isEmail) {
            return ['subject' => $subject, 'body' => $content];
        }
        $layout = file_get_contents(resource_path('views/notifications/layout.hbs'));

        return ['subject' => $subject, 'body' => Template::render($layout, $ctx + ['subject' => $subject, 'content' => $content])];
    }

    /**
     * Met en file les notifications client sur les canaux actifs, puis tente l'envoi.
     *
     * @param  array{name?: ?string, email?: ?string, phone?: ?string}  $to
     * @param  array{orderId?: ?int, forceChannels?: list<string>}  $opts
     */
    public function notifyCustomer(string $code, array $to, array $data, array $opts = []): void
    {
        $enabled = $this->settings->get('notifications');
        $targets = [
            NotificationChannelType::EMAIL->value => $to['email'] ?? null,
            NotificationChannelType::SMS->value => $to['phone'] ?? null,
            NotificationChannelType::WHATSAPP->value => $to['phone'] ?? null,
        ];
        $flags = ['EMAIL' => $enabled['email'], 'SMS' => $enabled['sms'], 'WHATSAPP' => $enabled['whatsapp']];
        foreach ($targets as $channel => $recipient) {
            $isEnabled = isset($opts['forceChannels']) ? in_array($channel, $opts['forceChannels'], true) : (bool) $flags[$channel];
            if (! $recipient || ! $isEnabled) {
                continue;
            }
            $tpl = $this->resolveTemplate($code, $channel);
            if (! $tpl) {
                continue;
            }
            $rendered = $this->render($channel, $tpl, ['customerName' => $to['name'] ?? ''] + $data);
            $row = Notification::create([
                'channel' => $channel,
                'recipient' => $recipient,
                'template' => $code,
                'payload' => $data,
                'subject' => $rendered['subject'],
                'body' => $rendered['body'],
                'status' => NotificationStatus::PENDING->value,
                'order_id' => $opts['orderId'] ?? null,
            ]);
            // Envoi après la réponse HTTP (équivalent du setImmediate de l'ancienne API).
            dispatch(fn () => app(self::class)->deliver($row->id))->afterResponse();
        }
    }

    public function deliver(int $id): void
    {
        $row = Notification::find($id);
        if (! $row) {
            return;
        }
        $attempts = $row->attempts + 1;
        try {
            $this->channels->send($row->channel, $row->recipient, $row->subject, $row->body ?? '');
            $row->update(['status' => NotificationStatus::SENT->value, 'attempts' => $attempts, 'sent_at' => now(), 'last_error' => null]);
        } catch (\Throwable $e) {
            Log::warning("Échec d'envoi {$row->channel} à {$row->recipient} (tentative {$attempts}) : {$e->getMessage()}");
            $row->update(['status' => NotificationStatus::FAILED->value, 'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 2000)]);
        }
    }

    /** Réessai des envois échoués (3 tentatives maximum). Planifié toutes les 5 minutes. */
    public function retryFailed(): void
    {
        Notification::where('status', NotificationStatus::FAILED->value)->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('updated_at')->limit(100)->pluck('id')->each(fn ($id) => $this->deliver($id));
    }

    /** Notification interne (cloche + temps réel). */
    public function notifyAdmins(string $type, string $title, ?string $link, ?int $adminUserId = null): array
    {
        $row = AdminNotification::create(['type' => $type, 'title' => mb_substr($title, 0, 190), 'link' => $link, 'admin_user_id' => $adminUserId])->refresh()->toArray();
        $adminUserId
            ? $this->realtime->emitToAdmin($adminUserId, 'notification.created', $row)
            : $this->realtime->emitToAdmins('notification.created', $row);

        return $row;
    }

    public function listAdmin(int $adminId, array $q): array
    {
        $mine = fn ($w) => $w->whereNull('admin_user_id')->orWhere('admin_user_id', $adminId);
        $total = AdminNotification::where($mine)->count();
        $rows = AdminNotification::where($mine)->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get()->toArray();
        $unread = AdminNotification::where($mine)->whereNull('read_at')->count();

        return Paginated::make($rows, $total, $q) + ['unread' => $unread];
    }

    public function markRead(int $id): array
    {
        AdminNotification::whereKey($id)->update(['read_at' => now()]);

        return ['success' => true];
    }

    public function markAllRead(int $adminId): array
    {
        AdminNotification::whereNull('read_at')->where(fn ($w) => $w->whereNull('admin_user_id')->orWhere('admin_user_id', $adminId))->update(['read_at' => now()]);

        return ['success' => true];
    }

    // ----- Gabarits ---------------------------------------------------------------

    public function listTemplates(): array
    {
        $rows = NotificationTemplate::orderBy('code')->orderBy('channel')->get();
        $out = $rows->toArray();
        foreach (NotificationTemplateCode::values() as $code) {
            foreach (NotificationChannelType::values() as $channel) {
                if (! $rows->contains(fn ($r) => $r->code === $code && $r->channel === $channel)) {
                    $tpl = self::defaultFor($code, $channel);
                    $out[] = ['code' => $code, 'channel' => $channel, 'name' => self::defaults($code)['name'], 'subject' => $tpl['subject'], 'body' => $tpl['body'], 'isActive' => true];
                }
            }
        }

        return $out;
    }

    public function saveTemplate(string $code, string $channel, array $input): array
    {
        try {
            Template::validate($input['body']);
            if (! empty($input['subject'])) {
                Template::validate($input['subject']);
            }
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(400, "Gabarit invalide : {$e->getMessage()}");
        }

        return NotificationTemplate::updateOrCreate(['code' => $code, 'channel' => $channel], [
            'name' => self::defaults($code)['name'],
            'subject' => $input['subject'] ?? null,
            'body' => $input['body'],
            'is_active' => $input['isActive'] ?? true,
        ])->refresh()->toArray();
    }

    public function preview(string $channel, array $tpl): array
    {
        return $this->render($channel, ['subject' => $tpl['subject'] ?? null, 'body' => $tpl['body']], [
            'customerName' => 'Awa Mba',
            'orderNumber' => 'AMO-2026-000123',
            'total' => '45 000 FCFA',
            'amount' => '22 500 FCFA',
            'balanceDue' => '22 500 FCFA',
            'trackingUrl' => 'https://amoria.ga/suivi/exemple',
            'stepLabel' => 'Expédié de Chine',
            'comment' => 'Votre colis a quitté Guangzhou.',
            'estimatedDate' => '12 mars 2026',
            'deliveryCode' => '4821',
            'productName' => 'Bouquet Héritage Rose poudré',
            'productUrl' => 'https://amoria.ga/produit/exemple',
            'resetUrl' => 'https://amoria.ga/compte/reinitialiser?token=exemple',
            'code' => '123456',
            'message' => 'Bonjour, votre bouquet est bien disponible.',
            'conversationUrl' => 'https://amoria.ga/?chat=1',
            'paymentInstructions' => 'Vous paierez à la livraison.',
            'reason' => 'solde insuffisant',
        ]);
    }
}
