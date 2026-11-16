<?php

namespace App\Services;

use App\Enums\ConversationStatus;
use App\Enums\OrderStatus;
use App\Enums\SenderType;
use App\Events\DomainEvent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Order;
use App\Models\Product;
use App\Models\QuickReply;
use App\Support\AdminPrincipal;
use App\Support\DefaultSettings;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MessagingService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly Realtime $realtime,
        private readonly AuditService $audit,
    ) {}

    private function mapMessage(Message $m): array
    {
        $senderName = match ($m->sender_type) {
            SenderType::ADMIN->value => $m->adminUser ? explode(' ', $m->adminUser->full_name)[0] : 'DutCh’M',
            SenderType::SYSTEM->value => 'DutCh’M',
            default => null,
        };

        return [
            'id' => $m->id,
            'senderType' => $m->sender_type,
            'senderName' => $senderName,
            'body' => $m->body,
            'attachmentUrl' => $m->attachment_url,
            'createdAt' => Format::iso($m->created_at),
        ];
    }

    private function toDto(Conversation $c): array
    {
        $msgs = Message::with('adminUser')->where('conversation_id', $c->id)->where('is_internal', false)->orderBy('created_at')->orderBy('id')->get();
        $product = $c->product_id ? Product::withTrashed()->find($c->product_id) : null;
        $order = $c->order_id ? Order::find($c->order_id) : null;

        return [
            'id' => $c->id,
            'publicToken' => $c->public_token,
            'status' => $c->status,
            'visitorName' => $c->visitor_name,
            'product' => $product ? ['id' => $product->id, 'name' => $product->name, 'reference' => $product->reference, 'slug' => $product->slug] : null,
            'orderNumber' => $order?->number,
            'messages' => $msgs->map(fn ($m) => $this->mapMessage($m))->all(),
        ];
    }

    private function addMessage(Conversation $c, string $senderType, string $body, array $extra = []): Message
    {
        return Message::create(['conversation_id' => $c->id, 'sender_type' => $senderType, 'body' => $body] + $extra)->refresh()->load('adminUser');
    }

    /** Nouvelle conversation (widget, « Poser une question », formulaire de contact). */
    public function start(array $dto, ?int $customerId): array
    {
        $phone = Format::normalizeGabonPhone($dto['phone']) ?? throw new HttpException(400, 'Numéro gabonais invalide (ex. +241 77 12 34 56)');
        $productId = null;
        if (! empty($dto['productId'])) {
            $productId = Product::find($dto['productId'])?->id ?? throw new HttpException(404, 'Produit introuvable');
        }
        $orderId = ! empty($dto['orderNumber'])
            ? Order::where('number', strtoupper($dto['orderNumber']))->where('contact_phone', $phone)->value('id')
            : null;
        $customer = $customerId ? Customer::find($customerId) : Customer::where('phone', $phone)->first();
        $c = Conversation::create([
            'customer_id' => $customer?->id,
            'visitor_name' => trim($dto['name']),
            'visitor_phone' => $phone,
            'visitor_email' => ! empty($dto['email']) ? strtolower($dto['email']) : null,
            'subject' => $dto['subject'] ?? null,
            'public_token' => Format::randomToken(32),
            'product_id' => $productId,
            'order_id' => $orderId,
            'status' => ConversationStatus::NEW->value,
            'last_message_at' => now(),
            'unread_for_admin' => 1,
        ])->refresh();
        $chat = $this->settings->get('chat');
        $this->addMessage($c, SenderType::SYSTEM->value, $chat['welcomeMessage']);
        $text = trim($dto['message']);
        $first = $this->addMessage($c, SenderType::CUSTOMER->value, ! empty($dto['subject']) ? "[{$dto['subject']}]\n{$text}" : $text);
        $support = $this->settings->get('supportHours');
        if (! DefaultSettings::isSupportOpen($support['slots'] ?? [])) {
            $this->addMessage($c, SenderType::SYSTEM->value, "{$chat['offHoursMessage']}\nHoraires : {$support['label']}.");
        }
        $this->realtime->emitToAdmins('message.created', ['conversationId' => $c->id, 'message' => $this->mapMessage($first), 'visitorName' => $c->visitor_name, 'isNew' => true]);
        DomainEvent::emit(DomainEvent::CONVERSATION_CUSTOMER_MESSAGE, ['conversationId' => $c->id, 'messageId' => $first->id]);

        return $this->toDto($c);
    }

    private function byToken(string $token): Conversation
    {
        return Conversation::where('public_token', $token)->first() ?? throw new HttpException(404, 'Conversation introuvable');
    }

    public function getByToken(string $token): array
    {
        $c = $this->byToken($token);
        if ($c->unread_for_customer) {
            $c->update(['unread_for_customer' => 0]);
        }
        Message::where('conversation_id', $c->id)->where('sender_type', SenderType::ADMIN->value)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->toDto($c);
    }

    public function postCustomer(string $token, string $body): array
    {
        $c = $this->byToken($token);
        $m = $this->addMessage($c, SenderType::CUSTOMER->value, trim($body));
        $c->update([
            'last_message_at' => now(),
            'unread_for_admin' => $c->unread_for_admin + 1,
            'status' => $c->status === ConversationStatus::RESOLVED->value ? ConversationStatus::OPEN->value : $c->status,
        ]);
        $dto = $this->mapMessage($m);
        $this->realtime->emitToAdmins('message.created', ['conversationId' => $c->id, 'message' => $dto, 'visitorName' => $c->visitor_name]);
        $this->realtime->emitToConversation($c->id, 'message.created', $dto);
        DomainEvent::emit(DomainEvent::CONVERSATION_CUSTOMER_MESSAGE, ['conversationId' => $c->id, 'messageId' => $m->id]);

        return $dto;
    }

    // ----- Administration ------------------------------------------------------------

    public function list(array $q, AdminPrincipal $actor): array
    {
        $qb = Conversation::query()->select('conversations.*')
            ->leftJoin('products as p', 'p.id', '=', 'conversations.product_id')
            ->leftJoin('orders as o', 'o.id', '=', 'conversations.order_id')
            ->with(['product', 'order', 'assignedTo']);
        if (! empty($q['status'])) {
            $qb->where('conversations.status', $q['status']);
        }
        if (! empty($q['mine'])) {
            $qb->where('conversations.assigned_to_id', $actor->id);
        }
        if (($q['linked'] ?? null) === 'product') {
            $qb->whereNotNull('conversations.product_id');
        }
        if (($q['linked'] ?? null) === 'order') {
            $qb->whereNotNull('conversations.order_id');
        }
        if (! empty($q['customerId'])) {
            $qb->where('conversations.customer_id', $q['customerId']);
        }
        if (! empty($q['q'])) {
            $like = "%{$q['q']}%";
            $qb->where(fn ($w) => $w->where('conversations.visitor_name', 'like', $like)->orWhere('conversations.visitor_phone', 'like', $like)
                ->orWhere('conversations.subject', 'like', $like)->orWhere('p.reference', 'like', $like)->orWhere('o.number', 'like', $like));
        }
        $total = (clone $qb)->count();
        $rows = $qb->orderByDesc('conversations.last_message_at')->forPage($q['page'], $q['limit'])->get();
        $last = $rows->isNotEmpty()
            ? Message::whereIn('id', Message::whereIn('conversation_id', $rows->pluck('id'))->where('is_internal', false)->groupBy('conversation_id')->selectRaw('MAX(id)'))->get()->keyBy('conversation_id')
            : collect();

        return Paginated::make($rows->map(fn ($c) => [
            'id' => $c->id,
            'visitorName' => $c->visitor_name,
            'visitorPhone' => $c->visitor_phone,
            'subject' => $c->subject,
            'status' => $c->status,
            'lastMessageAt' => Format::iso($c->last_message_at),
            'unreadForAdmin' => $c->unread_for_admin,
            'product' => $c->product ? ['id' => $c->product->id, 'name' => $c->product->name, 'reference' => $c->product->reference] : null,
            'order' => $c->order ? ['id' => $c->order->id, 'number' => $c->order->number] : null,
            'assignedTo' => $c->assignedTo ? ['id' => $c->assignedTo->id, 'fullName' => $c->assignedTo->full_name] : null,
            'lastMessage' => isset($last[$c->id]) ? mb_substr($last[$c->id]->body, 0, 140) : null,
        ])->all(), $total, $q) + ['totalUnread' => $this->unreadCount()];
    }

    public function unreadCount(): int
    {
        return (int) Conversation::sum('unread_for_admin');
    }

    public function detail(int $id): array
    {
        $c = Conversation::with(['assignedTo', 'customer'])->find($id) ?? throw new HttpException(404, 'Conversation introuvable');
        if ($c->unread_for_admin) {
            Conversation::whereKey($id)->update(['unread_for_admin' => 0]);
        }
        $msgs = Message::with('adminUser')->where('conversation_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $product = $c->product_id ? Product::withTrashed()->with(['images', 'variants'])->find($c->product_id) : null;
        $order = $c->order_id ? Order::find($c->order_id) : null;
        $customerOrders = Order::where('status', '<>', OrderStatus::CANCELLED->value)
            ->where($c->customer_id ? 'customer_id' : 'contact_phone', $c->customer_id ?: $c->visitor_phone)
            ->orderByDesc('created_at')->limit(10)->get();
        $cu = $c->customer;

        return $c->attributesToArrayCamel() + [
            'unreadForAdmin' => 0,
            'assignedTo' => $c->assignedTo ? ['id' => $c->assignedTo->id, 'fullName' => $c->assignedTo->full_name] : null,
            'customer' => $cu ? ['id' => $cu->id, 'fullName' => $cu->full_name, 'phone' => $cu->phone, 'email' => $cu->email, 'isGuest' => $cu->is_guest] : null,
            'messages' => $msgs->map(fn ($m) => $this->mapMessage($m) + ['isInternal' => $m->is_internal, 'adminUserId' => $m->admin_user_id])->all(),
            'product' => $product ? [
                'id' => $product->id,
                'name' => $product->name,
                'reference' => $product->reference,
                'slug' => $product->slug,
                'price' => $product->current_price,
                'availability' => $product->computed_availability,
                'thumbUrl' => $product->images->firstWhere('is_main', true)?->thumb_url ?? $product->images->first()?->thumb_url,
                'stock' => $product->variants->sum(fn ($v) => $v->stockAvailable()),
            ] : null,
            'order' => $order ? ['id' => $order->id, 'number' => $order->number, 'status' => $order->status, 'paymentStatus' => $order->payment_status, 'total' => $order->total] : null,
            'customerOrders' => $customerOrders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'total' => $o->total, 'createdAt' => Format::iso($o->created_at)])->all(),
            'totalSpent' => $customerOrders->sum(fn ($o) => $o->amount_paid - $o->amount_refunded),
        ];
    }

    public function reply(int $id, array $dto, AdminPrincipal $actor): array
    {
        $c = Conversation::find($id) ?? throw new HttpException(404, 'Conversation introuvable');
        $internal = ! empty($dto['isInternal']);
        $m = $this->addMessage($c, SenderType::ADMIN->value, trim($dto['body']), [
            'admin_user_id' => $actor->id, 'attachment_url' => $dto['attachmentUrl'] ?? null, 'is_internal' => $internal,
        ]);
        $dtoMsg = $this->mapMessage($m) + ['isInternal' => $m->is_internal, 'adminUserId' => $m->admin_user_id];
        if (! $internal) {
            $c->update([
                'last_message_at' => now(),
                'unread_for_customer' => $c->unread_for_customer + 1,
                'status' => $c->status === ConversationStatus::NEW->value ? ConversationStatus::OPEN->value : $c->status,
                'assigned_to_id' => $c->assigned_to_id ?? $actor->id,
            ]);
            $this->realtime->emitToConversation($id, 'message.created', $this->mapMessage($m));
            DomainEvent::emit(DomainEvent::CONVERSATION_ADMIN_REPLY, ['conversationId' => $id, 'messageId' => $m->id]);
        }
        $this->realtime->emitToAdmins('message.created', ['conversationId' => $id, 'message' => $dtoMsg, 'fromAdmin' => true]);

        return $dtoMsg;
    }

    public function assign(int $id, ?int $adminUserId, AdminPrincipal $actor, RequestMeta $meta): array
    {
        Conversation::whereKey($id)->update(['assigned_to_id' => $adminUserId]);
        $this->audit->log($actor->id, 'conversation.assign', 'conversation', $id, null, ['adminUserId' => $adminUserId], $meta);
        $this->realtime->emitToAdmins('conversation.updated', ['id' => $id]);

        return ['success' => true];
    }

    public function setStatus(int $id, string $status): array
    {
        Conversation::whereKey($id)->update(['status' => $status]);
        $this->realtime->emitToAdmins('conversation.updated', ['id' => $id, 'status' => $status]);

        return ['success' => true];
    }

    public function setNote(int $id, string $note): array
    {
        Conversation::whereKey($id)->update(['internal_note' => $note]);

        return ['success' => true];
    }

    public function listQuickReplies(): array
    {
        return QuickReply::orderBy('sort_order')->orderBy('title')->get()->toArray();
    }

    public function saveQuickReply(?int $id, array $dto): array
    {
        $q = $id ? (QuickReply::find($id) ?? throw new HttpException(404, 'Réponse rapide introuvable')) : new QuickReply;
        $q->fill(['title' => $dto['title'], 'body' => $dto['body']]);
        if (array_key_exists('sortOrder', $dto)) {
            $q->sort_order = $dto['sortOrder'];
        }
        $q->save();

        return $q->refresh()->toArray();
    }

    public function removeQuickReply(int $id): array
    {
        QuickReply::whereKey($id)->delete();

        return ['success' => true];
    }
}
