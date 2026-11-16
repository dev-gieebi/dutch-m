<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\SubjectType;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryZone;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Wishlist;
use App\Services\Auth\AdminAuthService;
use App\Services\Auth\CustomerAuthService;
use App\Services\Catalog\CatalogPublicService;
use App\Services\Orders\OrdersService;
use App\Support\AdminPrincipal;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Espace client (« Mon compte ») et gestion des clients dans le back-office. */
class CustomersService
{
    public function __construct(
        private readonly OrdersService $orders,
        private readonly CatalogPublicService $catalog,
        private readonly TokenService $tokens,
        private readonly AuditService $audit,
    ) {}

    private function me(int $id): Customer
    {
        return Customer::find($id) ?? throw new HttpException(404, 'Compte introuvable');
    }

    public function profile(int $id): array
    {
        return CustomerAuthService::toProfile($this->me($id));
    }

    public function update(int $id, array $dto): array
    {
        $c = $this->me($id);
        if (! empty($dto['fullName'])) {
            $c->full_name = trim($dto['fullName']);
        }
        if (array_key_exists('email', $dto)) {
            $email = ! empty($dto['email']) ? strtolower($dto['email']) : null;
            if ($email && Customer::where('email', $email)->where('is_guest', false)->where('id', '<>', $id)->exists()) {
                throw new HttpException(409, 'Cette adresse e-mail est déjà utilisée');
            }
            $c->email = $email;
        }
        if (array_key_exists('newsletterConsent', $dto)) {
            $c->newsletter_consent = (bool) $dto['newsletterConsent'];
            $c->newsletter_consent_at = $dto['newsletterConsent'] ? now() : null;
        }
        if (! empty($dto['newPassword'])) {
            if (empty($dto['currentPassword']) || ! AdminAuthService::verifyPassword($dto['currentPassword'], $c->password_hash)) {
                throw new HttpException(400, 'Mot de passe actuel incorrect');
            }
            $c->password_hash = AdminAuthService::hashPassword($dto['newPassword']);
        }
        $c->save();

        return CustomerAuthService::toProfile($c);
    }

    /** Suppression du compte : anonymisation des données personnelles, conservation des commandes (obligations comptables). */
    public function anonymize(int $id, ?string $password = null, ?int $adminId = null, ?RequestMeta $meta = null): array
    {
        $c = Customer::find($id) ?? throw new HttpException(404, 'Compte introuvable');
        if ($password !== null && ! AdminAuthService::verifyPassword($password, $c->password_hash)) {
            throw new HttpException(400, 'Mot de passe incorrect');
        }
        DB::transaction(function () use ($c, $id) {
            $c->forceFill([
                'full_name' => 'Client supprimé', 'phone' => "deleted-{$id}", 'email' => null, 'password_hash' => null, 'notes' => null,
                'newsletter_consent' => false, 'newsletter_consent_at' => null, 'is_guest' => true,
            ])->save();
            $c->delete();
            CustomerAddress::where('customer_id', $id)->delete();
            Wishlist::where('customer_id', $id)->delete();
            Cart::where('customer_id', $id)->delete();
            Order::where('customer_id', $id)->update(['contact_name' => 'Client supprimé', 'contact_email' => null, 'landmark' => null, 'instructions' => null, 'district' => null]);
            Conversation::where('customer_id', $id)->update(['visitor_name' => 'Client supprimé', 'visitor_email' => null]);
        });
        $this->tokens->revokeAll(SubjectType::CUSTOMER, $id);
        if ($adminId) {
            $this->audit->log($adminId, 'customer.anonymize', 'customer', $id, null, null, $meta);
        }

        return ['success' => true];
    }

    /** Commandes visibles : rattachées et (téléphone vérifié, ou passées après l'inscription, ou avec un autre téléphone). */
    private function visibleOrders(int $customerId): Builder
    {
        return Order::query()->select('orders.*')
            ->join('customers as c', 'c.id', '=', 'orders.customer_id')
            ->where('orders.customer_id', $customerId)
            ->where(fn ($w) => $w->whereNotNull('c.phone_verified_at')
                ->orWhereColumn('orders.created_at', '>=', 'c.registered_at')
                ->orWhereColumn('orders.contact_phone', '<>', 'c.phone'));
    }

    public function myOrders(int $customerId): array
    {
        return $this->visibleOrders($customerId)->orderByDesc('orders.created_at')->limit(100)->get()->map(fn ($o) => [
            'id' => $o->id, 'number' => $o->number, 'createdAt' => Format::iso($o->created_at), 'status' => $o->status, 'paymentStatus' => $o->payment_status,
            'total' => $o->total, 'balanceDue' => $o->balance_due, 'orderType' => $o->order_type, 'trackingToken' => $o->tracking_token,
        ])->all();
    }

    public function myOrder(int $customerId, string $number): array
    {
        $o = $this->visibleOrders($customerId)->where('orders.number', strtoupper($number))->first() ?? throw new HttpException(404, 'Commande introuvable');

        return $this->orders->toTracking($o->id) + [
            'trackingToken' => $o->tracking_token,
            'items' => OrderItem::where('order_id', $o->id)->get()->toArray(),
            'contact' => ['name' => $o->contact_name, 'phone' => $o->contact_phone, 'email' => $o->contact_email],
            'address' => ['city' => $o->city, 'zone' => $o->zone_name, 'district' => $o->district, 'landmark' => $o->landmark, 'instructions' => $o->instructions],
        ];
    }

    public function listAddresses(int $customerId): array
    {
        return CustomerAddress::where('customer_id', $customerId)->orderByDesc('is_default')->orderByDesc('created_at')->get()->toArray();
    }

    private function fillAddress(CustomerAddress $a, array $dto): void
    {
        foreach (['label', 'city', 'zoneId', 'district', 'landmark', 'instructions', 'isDefault'] as $k) {
            if (array_key_exists($k, $dto)) {
                $a->setAttribute(Str::snake($k), $dto[$k]);
            }
        }
        $a->zone_name = ! empty($dto['zoneId']) ? DeliveryZone::whereKey($dto['zoneId'])->value('name') : null;
    }

    public function addAddress(int $customerId, array $dto): array
    {
        $count = CustomerAddress::where('customer_id', $customerId)->count();
        if ($count >= 10) {
            throw new HttpException(400, '10 adresses maximum');
        }
        $a = new CustomerAddress(['customer_id' => $customerId]);
        $this->fillAddress($a, $dto);
        $a->is_default = $dto['isDefault'] ?? $count === 0;
        $a->save();
        if ($a->is_default) {
            CustomerAddress::where('customer_id', $customerId)->where('id', '<>', $a->id)->update(['is_default' => false]);
        }

        return $a->refresh()->toArray();
    }

    public function updateAddress(int $customerId, int $id, array $dto): array
    {
        $a = CustomerAddress::where('id', $id)->where('customer_id', $customerId)->first() ?? throw new HttpException(404, 'Adresse introuvable');
        $this->fillAddress($a, $dto);
        $a->save();
        if ($a->is_default) {
            CustomerAddress::where('customer_id', $customerId)->where('id', '<>', $a->id)->update(['is_default' => false]);
        }

        return $a->toArray();
    }

    public function removeAddress(int $customerId, int $id): array
    {
        CustomerAddress::where('id', $id)->where('customer_id', $customerId)->delete();

        return ['success' => true];
    }

    public function wishlist(int $customerId): array
    {
        return $this->catalog->loadCards(Wishlist::where('customer_id', $customerId)->orderByDesc('created_at')->pluck('product_id')->all());
    }

    public function addWishlist(int $customerId, int $productId): array
    {
        Wishlist::firstOrCreate(['customer_id' => $customerId, 'product_id' => $productId]);

        return ['success' => true];
    }

    public function removeWishlist(int $customerId, int $productId): array
    {
        Wishlist::where('customer_id', $customerId)->where('product_id', $productId)->delete();

        return ['success' => true];
    }

    public function getCart(int $customerId): array
    {
        $cart = Cart::with('items')->where('customer_id', $customerId)->first();

        return ['items' => ($cart?->items ?? collect())->map(fn ($i) => ['variantId' => $i->variant_id, 'quantity' => $i->quantity])->values()->all()];
    }

    public function putCart(int $customerId, array $items): array
    {
        DB::transaction(function () use ($customerId, $items) {
            $cart = Cart::firstOrCreate(['customer_id' => $customerId]);
            CartItem::where('cart_id', $cart->id)->delete();
            $merged = [];
            foreach ($items as $i) {
                $merged[(int) $i['variantId']] = ($merged[(int) $i['variantId']] ?? 0) + (int) $i['quantity'];
            }
            foreach ($merged as $variantId => $quantity) {
                DB::insert('INSERT INTO cart_items (cart_id, variant_id, quantity, created_at, updated_at) SELECT ?, id, ?, NOW(3), NOW(3) FROM product_variants WHERE id = ?', [$cart->id, $quantity, $variantId]);
            }
        });

        return $this->getCart($customerId);
    }

    // ----- Administration ------------------------------------------------------------

    public function adminList(array $q): array
    {
        $filter = function ($qb) use ($q) {
            if (! empty($q['q'])) {
                $like = "%{$q['q']}%";
                $qb->where(fn ($w) => $w->where('customers.full_name', 'like', $like)->orWhere('customers.phone', 'like', $like)->orWhere('customers.email', 'like', $like));
            }
            if (! empty($q['newsletter'])) {
                $qb->where('customers.newsletter_consent', true);
            }

            return $qb;
        };
        $total = $filter(Customer::query())->count();
        $rows = $filter(Customer::query()->select('customers.*')
            ->leftJoin('orders as o', fn ($j) => $j->on('o.customer_id', '=', 'customers.id')->where('o.status', '<>', OrderStatus::CANCELLED->value))
            ->selectRaw('COUNT(o.id) AS agg_orders, COALESCE(SUM(o.amount_paid - o.amount_refunded), 0) AS agg_spent, MAX(o.created_at) AS agg_last')
            ->groupBy('customers.id'))
            ->orderByDesc('customers.created_at')->forPage($q['page'], $q['limit'])->get();

        return Paginated::make($rows->map(function ($c) {
            $arr = $c->toArray();
            unset($arr['aggOrders'], $arr['aggSpent'], $arr['aggLast']);

            return $arr + ['ordersCount' => (int) $c->agg_orders, 'totalSpent' => (int) $c->agg_spent, 'lastOrderAt' => Format::iso($c->agg_last)];
        })->all(), $total, $q);
    }

    public function adminDetail(int $id): array
    {
        $c = Customer::withTrashed()->with('addresses')->find($id) ?? throw new HttpException(404, 'Client introuvable');
        $orders = Order::where('customer_id', $id)->orderByDesc('created_at')->limit(100)->get();
        $conversations = Conversation::where('customer_id', $id)->orderByDesc('last_message_at')->limit(50)->get();

        return $c->toArray() + [
            'orders' => $orders->map(fn ($o) => ['id' => $o->id, 'number' => $o->number, 'createdAt' => Format::iso($o->created_at), 'status' => $o->status, 'paymentStatus' => $o->payment_status, 'total' => $o->total])->all(),
            'totalSpent' => $orders->where('status', '<>', OrderStatus::CANCELLED->value)->sum(fn ($o) => $o->amount_paid - $o->amount_refunded),
            'conversations' => $conversations->map(fn ($cv) => ['id' => $cv->id, 'status' => $cv->status, 'subject' => $cv->subject, 'lastMessageAt' => Format::iso($cv->last_message_at)])->all(),
            'wishlistCount' => Wishlist::where('customer_id', $id)->count(),
        ];
    }

    public function adminUpdate(int $id, array $dto, AdminPrincipal $actor, RequestMeta $meta): array
    {
        $c = $this->me($id);
        $before = $c->attributesToArrayCamel();
        foreach (['notes', 'fullName', 'email', 'newsletterConsent'] as $k) {
            if (array_key_exists($k, $dto)) {
                $c->setAttribute(Str::snake($k), $dto[$k]);
            }
        }
        if (array_key_exists('newsletterConsent', $dto)) {
            $c->newsletter_consent_at = $dto['newsletterConsent'] ? now() : null;
        }
        $c->save();
        $this->audit->log($actor->id, 'customer.update', 'customer', $id, $before, $dto, $meta);

        return $c->toArray();
    }
}
