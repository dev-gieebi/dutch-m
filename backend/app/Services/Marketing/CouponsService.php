<?php

namespace App\Services\Marketing;

use App\Models\Coupon;
use App\Models\CouponUsage;
use App\Services\AuditService;
use App\Support\AdminPrincipal;
use App\Support\Format;
use App\Support\Paginated;
use App\Support\RequestMeta;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CouponsService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * Vérifie l'éligibilité d'un code (dates, limites d'utilisation). Le calcul de remise est fait par Pricing.
     *
     * @return array{coupon: ?Coupon, error: ?string}
     */
    public function findUsable(string $code, ?string $phone = null, ?int $customerId = null): array
    {
        $coupon = Coupon::where('code', strtoupper(trim($code)))->first();
        if (! $coupon || ! $coupon->is_active) {
            return ['coupon' => null, 'error' => 'Ce code promo est invalide'];
        }
        if (! Format::isWithin($coupon->starts_at, $coupon->ends_at)) {
            return ['coupon' => null, 'error' => "Ce code promo n'est pas valable actuellement"];
        }
        if ($coupon->usage_limit !== null && $coupon->used_count >= $coupon->usage_limit) {
            return ['coupon' => null, 'error' => 'Ce code promo a atteint sa limite d’utilisation'];
        }
        if ($coupon->usage_limit_per_customer !== null && ($phone || $customerId)) {
            $q = CouponUsage::where('coupon_id', $coupon->id);
            if ($customerId && $phone) {
                $q->where(fn ($w) => $w->where('customer_id', $customerId)->orWhere('phone', $phone));
            } elseif ($customerId) {
                $q->where('customer_id', $customerId);
            } else {
                $q->where('phone', $phone);
            }
            if ($q->count() >= $coupon->usage_limit_per_customer) {
                return ['coupon' => null, 'error' => 'Vous avez déjà utilisé ce code promo'];
            }
        }

        return ['coupon' => $coupon, 'error' => null];
    }

    public static function rule(Coupon $c): array
    {
        return [
            'type' => $c->type, 'value' => $c->value, 'minSubtotal' => $c->min_subtotal, 'maxDiscount' => $c->max_discount,
            'categoryIds' => $c->category_ids, 'productIds' => $c->product_ids,
        ];
    }

    public function list(array $q): array
    {
        $qb = Coupon::query();
        if (! empty($q['q'])) {
            $qb->where(fn ($w) => $w->where('code', 'like', "%{$q['q']}%")->orWhere('description', 'like', "%{$q['q']}%"));
        }
        $total = (clone $qb)->count();

        return Paginated::make($qb->orderByDesc('created_at')->forPage($q['page'], $q['limit'])->get()->toArray(), $total, $q);
    }

    public function get(int $id): array
    {
        $c = Coupon::find($id) ?? throw new HttpException(404, 'Code promo introuvable');

        return $c->toArray() + ['usages' => CouponUsage::where('coupon_id', $id)->orderByDesc('created_at')->limit(100)->get()->toArray()];
    }

    private function normalize(Coupon $c, array $dto): void
    {
        foreach ($dto as $k => $v) {
            if (in_array($k, ['startsAt', 'endsAt'], true)) {
                $c->setAttribute(Str::snake($k), $v ? Carbon::parse($v) : null);
            } else {
                $c->setAttribute(Str::snake($k), $k === 'code' && $v ? strtoupper($v) : $v);
            }
        }
        if ($c->type === 'PERCENT' && array_key_exists('value', $dto) && ($c->value < 1 || $c->value > 100)) {
            throw new HttpException(400, 'Le pourcentage doit être compris entre 1 et 100');
        }
    }

    public function create(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $c = new Coupon;
        $this->normalize($c, $dto);
        $c->save();
        $c->refresh();
        $this->audit->log($actor->id, 'coupon.create', 'coupon', $c->id, null, $c, $meta);

        return $c->toArray();
    }

    public function update(AdminPrincipal $actor, int $id, array $dto, RequestMeta $meta): array
    {
        $c = Coupon::find($id) ?? throw new HttpException(404, 'Code promo introuvable');
        $before = $c->attributesToArrayCamel();
        $this->normalize($c, $dto);
        $c->save();
        $this->audit->log($actor->id, 'coupon.update', 'coupon', $id, $before, $c, $meta);

        return $c->toArray();
    }

    public function remove(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        Coupon::whereKey($id)->update(['is_active' => false]);
        $this->audit->log($actor->id, 'coupon.deactivate', 'coupon', $id, null, null, $meta);

        return ['success' => true];
    }
}
