<?php

namespace App\Services\Orders;

use App\Enums\AvailabilityMode;
use App\Enums\DeliveryGrouping;
use App\Enums\DeliveryMode;
use App\Enums\ItemType;
use App\Enums\PaymentMethod;
use App\Enums\ProductStatus;
use App\Models\DeliveryZone;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Services\Catalog\Availability;
use App\Services\Marketing\CouponsService;
use App\Services\SettingsService;
use App\Support\Format;

class CartService
{
    public function __construct(
        private readonly CouponsService $coupons,
        private readonly SettingsService $settings,
    ) {}

    /** @return list<string> */
    public function allowedPaymentMethods(bool $hasPreorder): array
    {
        $p = $this->settings->get('payments');
        $methods = [];
        if ($p['cashOnDelivery'] && (! $hasPreorder || $p['codForPreorder'])) {
            $methods[] = PaymentMethod::CASH_ON_DELIVERY->value;
        }
        if ($p['airtelMoney']) {
            $methods[] = PaymentMethod::AIRTEL_MONEY->value;
        }
        if ($p['moovMoney']) {
            $methods[] = PaymentMethod::MOOV_MONEY->value;
        }

        return $methods;
    }

    /**
     * Re-vérifie prix, stock, coupon et calcule tous les montants (règles 5.4, 6.1, 6.3, 6.4, 6.8).
     *
     * @param  array{items: list<array{variantId: int, quantity: int, expectedPrice?: int}>, couponCode?: ?string, zoneId?: ?int, deliveryMode?: ?string, grouping?: ?string, phone?: ?string, customerId?: ?int}  $input
     * @return array{result: array, context: array}
     */
    public function validate(array $input): array
    {
        $merged = [];
        foreach ($input['items'] as $i) {
            $id = (int) $i['variantId'];
            $merged[$id] = ['variantId' => $id, 'quantity' => ($merged[$id]['quantity'] ?? 0) + (int) $i['quantity'], 'expectedPrice' => $i['expectedPrice'] ?? null];
        }
        $variants = $merged
            ? ProductVariant::with(['product' => fn ($q) => $q->withTrashed()->with('category')])->whereIn('id', array_keys($merged))->get()->keyBy('id')
            : collect();
        $productIds = $variants->pluck('product_id')->unique()->values()->all();
        $imgs = $productIds ? ProductImage::whereIn('product_id', $productIds)->orderByDesc('is_main')->orderBy('sort_order')->get() : collect();

        $payments = $this->settings->get('payments');
        $maxPreorder = (int) $this->settings->get('preorder')['maxQtyPerOrder'];
        $now = now();
        $lines = [];
        $pricingLines = [];
        $issues = [];
        $blocking = false;

        foreach ($merged as $variantId => $item) {
            $v = $variants[$variantId] ?? null;
            $p = $v?->product;
            $label = $p ? $p->name.($v->name !== 'Standard' ? " – {$v->name}" : '') : 'Article';
            $lineIssues = [];
            if (! $v || ! $p || $p->deleted_at || $p->status !== ProductStatus::PUBLISHED->value || ! $v->is_active) {
                $issues[] = "« {$label} » n'est plus disponible et a été retiré.";
                $blocking = true;

                continue;
            }
            $availability = Availability::variant($p, $v);
            $max = Availability::maxOrderQuantity($p, $v, $maxPreorder);
            $price = Availability::effectivePrice($v, $now);
            $quantity = $item['quantity'];
            if ($availability === AvailabilityMode::UNAVAILABLE->value) {
                $lineIssues[] = 'Cet article est actuellement indisponible.';
                $quantity = 0;
                $blocking = true;
            } elseif ($quantity > $max) {
                $lineIssues[] = $availability === AvailabilityMode::IN_STOCK->value
                    ? "Quantité ajustée à {$max} (stock disponible)."
                    : "Quantité limitée à {$max} par commande.";
                $quantity = $max;
                $blocking = true;
            }
            if ($item['expectedPrice'] !== null && (int) $item['expectedPrice'] !== $price['price']) {
                $lineIssues[] = 'Le prix a changé : '.Format::fcfa($item['expectedPrice']).' → '.Format::fcfa($price['price']).'.';
            }
            $itemType = $availability === AvailabilityMode::IN_STOCK->value ? ItemType::LOCAL->value : ItemType::PREORDER->value;
            $img = $imgs->first(fn ($i) => $i->variant_id === $v->id) ?? $imgs->first(fn ($i) => $i->product_id === $p->id);
            foreach ($lineIssues as $m) {
                $issues[] = "« {$label} » : {$m}";
            }
            if ($quantity > 0) {
                $pricingLines[] = [
                    'variantId' => $v->id,
                    'productId' => $p->id,
                    'categoryId' => $p->category_id,
                    'unitPrice' => $price['price'],
                    'quantity' => $quantity,
                    'itemType' => $itemType,
                    'depositPercent' => Availability::depositPercentFor($p, (int) $payments['depositPercent']),
                    'leadTimeDays' => $p->lead_time_days,
                ];
            }
            $lines[] = [
                'variantId' => $v->id,
                'productId' => $p->id,
                'productSlug' => $p->slug,
                'name' => $p->name,
                'variantName' => $v->name,
                'sku' => $v->sku,
                'imageUrl' => $img?->thumb_url,
                'unitPrice' => $price['price'],
                'compareAtPrice' => $price['compareAtPrice'],
                'quantity' => $quantity,
                'requestedQuantity' => $item['quantity'],
                'lineTotal' => $price['price'] * $quantity,
                'itemType' => $itemType,
                'availability' => $availability,
                'leadTimeDays' => $p->lead_time_days,
                'maxQuantity' => $max,
                'depositAmount' => 0,
                'issues' => $lineIssues,
            ];
        }

        $coupon = null;
        $couponError = null;
        $discount = 0;
        $couponFreeShipping = false;
        $phone = ! empty($input['phone']) ? Format::normalizeGabonPhone($input['phone']) : null;
        if (trim((string) ($input['couponCode'] ?? '')) !== '') {
            $found = $this->coupons->findUsable($input['couponCode'], $phone, $input['customerId'] ?? null);
            $couponError = $found['error'];
            if ($found['coupon']) {
                $ev = Pricing::evaluateCoupon(CouponsService::rule($found['coupon']), $pricingLines);
                if ($ev['error']) {
                    $couponError = $ev['error'];
                } else {
                    $coupon = $found['coupon'];
                    $discount = $ev['discount'];
                    $couponFreeShipping = $ev['freeShipping'];
                }
            }
        }

        $deliveryMode = $input['deliveryMode'] ?? DeliveryMode::HOME->value;
        $zone = ! empty($input['zoneId']) ? DeliveryZone::where('id', $input['zoneId'])->where('is_active', true)->first() : null;
        $totals = Pricing::computeTotals([
            'lines' => $pricingLines,
            'discount' => $discount,
            'couponFreeShipping' => $couponFreeShipping,
            'zoneFee' => $zone?->fee,
            'deliveryMode' => $deliveryMode,
            'grouping' => $input['grouping'] ?? DeliveryGrouping::SPLIT->value,
            'freeShippingThreshold' => $this->settings->get('freeShippingThreshold'),
        ]);
        foreach ($pricingLines as $i => $pl) {
            foreach ($lines as &$line) {
                if ($line['variantId'] === $pl['variantId']) {
                    $line['depositAmount'] = $totals['lineDeposits'][$i];
                }
            }
            unset($line);
        }

        $hasLocal = (bool) array_filter($pricingLines, fn ($l) => $l['itemType'] === ItemType::LOCAL->value);
        $preLines = array_filter($pricingLines, fn ($l) => $l['itemType'] === ItemType::PREORDER->value);
        $hasPreorder = (bool) $preLines;
        $leadTime = max([0, ...array_column($preLines, 'leadTimeDays')]);
        $fullPaymentRequired = false;
        foreach ($preLines as $l) {
            if ($variants[$l['variantId']]->product->full_payment_required) {
                $fullPaymentRequired = true;
            }
        }
        $publicTotals = $totals;
        unset($publicTotals['lineDeposits'], $publicTotals['feePerShipment']);

        return [
            'result' => [
                'lines' => $lines,
                'totals' => $publicTotals,
                'coupon' => $coupon ? ['code' => $coupon->code, 'description' => $coupon->description, 'discount' => $discount, 'freeShipping' => $couponFreeShipping] : null,
                'couponError' => $couponError,
                'issues' => $issues,
                'hasLocal' => $hasLocal,
                'hasPreorder' => $hasPreorder,
                'preorderLeadTimeDays' => $leadTime,
                'estimatedPreorderDate' => $hasPreorder ? Format::iso($now->copy()->addDays($leadTime)) : null,
                'depositPercent' => (int) $payments['depositPercent'],
                'fullPaymentRequired' => $fullPaymentRequired,
                'allowedPaymentMethods' => $this->allowedPaymentMethods($hasPreorder),
            ],
            'context' => [
                'variants' => $variants,
                'pricingLines' => $pricingLines,
                'zone' => $zone,
                'coupon' => $coupon,
                'feePerShipment' => $totals['feePerShipment'],
                'lineDeposits' => $totals['lineDeposits'],
                'blocking' => $blocking,
            ],
        ];
    }
}
