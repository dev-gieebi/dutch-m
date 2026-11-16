<?php

namespace Tests\Unit;

use App\Services\Arrivals\Propagation;
use App\Services\Orders\OrderState;
use App\Support\Domain;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderStateTest extends TestCase
{
    private function assertRejects(callable $fn, string $pattern): void
    {
        try {
            $fn();
            $this->fail('Exception attendue');
        } catch (HttpException $e) {
            $this->assertMatchesRegularExpression($pattern, $e->getMessage());
        }
    }

    public function test_enchainement_des_etapes(): void
    {
        $preorder = Domain::stepSequence('PREORDER', 'HOME');
        $pickup = Domain::stepSequence('LOCAL', 'PICKUP');
        OrderState::assertNextStep($preorder, 'ORDER_CONFIRMED', 'ORDERED_FROM_SUPPLIER');
        $this->assertSame('RECEIVED_IN_STOCK', OrderState::nextStepOf($preorder, 'ARRIVED_IN_GABON'));
        $this->assertRejects(fn () => OrderState::assertNextStep($preorder, 'ORDER_CONFIRMED', 'ARRIVED_IN_GABON'), "/dans l'ordre/");
        OrderState::assertNextStep($preorder, 'ORDER_CONFIRMED', 'ARRIVED_IN_GABON', true);
        $this->assertRejects(fn () => OrderState::assertNextStep($preorder, 'SHIPPED_FROM_CHINA', 'ORDERED_FROM_SUPPLIER'), '/déjà/');
        $this->assertRejects(fn () => OrderState::assertNextStep($pickup, 'PREPARING', 'OUT_FOR_DELIVERY'), "/ne s'applique pas/");
        OrderState::assertNextStep($pickup, 'PREPARING', 'READY_FOR_PICKUP');
        $this->assertRejects(fn () => OrderState::assertNextStep($preorder, 'CANCELLED', 'ORDERED_FROM_SUPPLIER'), '/clôturée/');
        $this->assertRejects(fn () => OrderState::assertNextStep($preorder, 'DELIVERED', 'CANCELLED'), '/livrée/');
    }

    public function test_statuts_derives(): void
    {
        $this->assertSame('CONFIRMED', OrderState::computeOrderStatus('CONFIRMED', ['ORDER_CONFIRMED']));
        $this->assertSame('IN_PROGRESS', OrderState::computeOrderStatus('CONFIRMED', ['PREPARING']));
        $this->assertSame('PARTIALLY_DELIVERED', OrderState::computeOrderStatus('IN_PROGRESS', ['DELIVERED', 'SHIPPED_FROM_CHINA']));
        $this->assertSame('DELIVERED', OrderState::computeOrderStatus('PARTIALLY_DELIVERED', ['DELIVERED', 'DELIVERED']));
        $this->assertSame('PENDING_PAYMENT', OrderState::computeOrderStatus('PENDING_PAYMENT', [null]));

        $s = fn (int $paid, int $refunded = 0, string $status = 'PENDING', string $method = 'AIRTEL_MONEY') => OrderState::computePaymentStatus(32_000, $paid, $refunded, $status, $method);
        $this->assertSame('DEPOSIT_PAID', $s(17_000));
        $this->assertSame('PAID', $s(32_000));
        $this->assertSame('PARTIALLY_REFUNDED', $s(32_000, 5_000));
        $this->assertSame('REFUNDED', $s(32_000, 32_000));
        $this->assertSame('AWAITING_CASH', $s(0, 0, 'AWAITING_CASH', 'CASH_ON_DELIVERY'));
    }

    public function test_propagation_des_arrivages(): void
    {
        $i = fn (?string $step, int $q, int $r = 0) => ['arrivalStep' => $step, 'quantity' => $q, 'receivedQty' => $r];
        $this->assertSame('ARRIVED_IN_GABON', Propagation::computeShipmentTarget([$i('ARRIVED_IN_GABON', 2)]));
        $this->assertSame('SHIPPED_FROM_CHINA', Propagation::computeShipmentTarget([$i('ARRIVED_IN_GABON', 1), $i('SHIPPED_FROM_CHINA', 1)]));
        $this->assertSame('ORDER_CONFIRMED', Propagation::computeShipmentTarget([$i('ARRIVED_IN_GABON', 1), $i(null, 1)]));
        $this->assertSame('ARRIVED_IN_GABON', Propagation::computeShipmentTarget([$i('RECEIVED_IN_STOCK', 3, 2)]));
        $this->assertSame('RECEIVED_IN_STOCK', Propagation::computeShipmentTarget([$i('RECEIVED_IN_STOCK', 3, 3)]));
        $this->assertSame(['ORDERED_FROM_SUPPLIER', 'SHIPPED_FROM_CHINA', 'IN_INTERNATIONAL_TRANSIT'], Propagation::stepsBetween('ORDER_CONFIRMED', 'IN_INTERNATIONAL_TRANSIT'));
        $this->assertSame([], Propagation::stepsBetween('ARRIVED_IN_GABON', 'SHIPPED_FROM_CHINA'));
    }
}
