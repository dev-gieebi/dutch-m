<?php

namespace Tests\Unit;

use App\Services\Orders\Pricing;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    private const COUPON = ['type' => 'PERCENT', 'value' => 10, 'minSubtotal' => 0, 'maxDiscount' => null, 'categoryIds' => null, 'productIds' => null];

    private function local(array $over = []): array
    {
        return $over + ['variantId' => 1, 'productId' => 1, 'categoryId' => 10, 'unitPrice' => 10_000, 'quantity' => 2, 'itemType' => 'LOCAL', 'depositPercent' => 50, 'leadTimeDays' => 0];
    }

    private function preorder(array $over = []): array
    {
        return $over + ['variantId' => 2, 'productId' => 2, 'categoryId' => 20, 'unitPrice' => 30_000, 'quantity' => 1, 'itemType' => 'PREORDER', 'depositPercent' => 50, 'leadTimeDays' => 14];
    }

    private function totals(array $over): array
    {
        return Pricing::computeTotals($over + ['discount' => 0, 'couponFreeShipping' => false, 'zoneFee' => 2_000, 'deliveryMode' => 'HOME', 'grouping' => 'SPLIT', 'freeShippingThreshold' => null]);
    }

    public function test_panier_en_stock_avec_livraison(): void
    {
        $t = $this->totals(['lines' => [$this->local()]]);
        $this->assertSame([20_000, 2_000, 22_000, 0, 22_000, 0, 1], [$t['subtotal'], $t['shippingTotal'], $t['total'], $t['depositAmount'], $t['dueNow'], $t['balanceAfter'], $t['shipmentsCount']]);
    }

    public function test_acompte_de_precommande(): void
    {
        $t = $this->totals(['lines' => [$this->preorder()]]);
        $this->assertSame([15_000, 32_000, 17_000, 15_000], [$t['depositAmount'], $t['total'], $t['dueNow'], $t['balanceAfter']]);
    }

    public function test_paiement_total_obligatoire(): void
    {
        $t = $this->totals(['lines' => [$this->preorder(['depositPercent' => 100])]]);
        $this->assertSame(30_000, $t['depositAmount']);
        $this->assertSame($t['total'], $t['dueNow']);
    }

    public function test_deux_livraisons_pour_commande_mixte_separee(): void
    {
        $split = $this->totals(['lines' => [$this->local(), $this->preorder()]]);
        $together = $this->totals(['grouping' => 'TOGETHER', 'lines' => [$this->local(), $this->preorder()]]);
        $this->assertSame([2, 4_000], [$split['shipmentsCount'], $split['shippingTotal']]);
        $this->assertSame([1, 2_000], [$together['shipmentsCount'], $together['shippingTotal']]);
    }

    public function test_livraison_offerte_seuil_et_retrait(): void
    {
        $this->assertSame(0, $this->totals(['freeShippingThreshold' => 15_000, 'lines' => [$this->local()]])['shippingTotal']);
        $this->assertSame(0, $this->totals(['deliveryMode' => 'PICKUP', 'lines' => [$this->local()]])['shippingTotal']);
    }

    public function test_remise_plafonnee_au_sous_total(): void
    {
        $t = $this->totals(['discount' => 999_999, 'lines' => [$this->local()]]);
        $this->assertSame([20_000, 2_000], [$t['discountTotal'], $t['total']]);
    }

    public function test_codes_promo(): void
    {
        $this->assertSame(2_000, Pricing::evaluateCoupon(self::COUPON, [$this->local()])['discount']);
        $this->assertSame(3_000, Pricing::evaluateCoupon(['value' => 50, 'maxDiscount' => 3_000] + self::COUPON, [$this->local()])['discount']);
        $this->assertSame(20_000, Pricing::evaluateCoupon(['type' => 'FIXED', 'value' => 50_000] + self::COUPON, [$this->local()])['discount']);
        $this->assertStringContainsString('Montant minimum', Pricing::evaluateCoupon(['minSubtotal' => 100_000] + self::COUPON, [$this->local()])['error']);
        $this->assertSame(3_000, Pricing::evaluateCoupon(['categoryIds' => [20]] + self::COUPON, [$this->local(), $this->preorder()])['discount']);
        $this->assertNotNull(Pricing::evaluateCoupon(['categoryIds' => [99]] + self::COUPON, [$this->local()])['error']);
        $free = Pricing::evaluateCoupon(['type' => 'FREE_SHIPPING', 'value' => 0] + self::COUPON, [$this->local()]);
        $this->assertSame(['discount' => 0, 'freeShipping' => true, 'error' => null], $free);
        $this->assertSame(0, $this->totals(['couponFreeShipping' => true, 'lines' => [$this->local()]])['shippingTotal']);
    }
}
