<?php

use App\Enums\CouponType;
use App\Enums\DeliveryGrouping;
use App\Enums\DeliveryMode;
use App\Enums\ItemType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Enums\PaymentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentRecordStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Enums\ShipmentStep;
use App\Support\Schema\Columns as C;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_zones', function (Blueprint $t) {
            C::base($t);
            $t->string('city', 80)->index();
            $t->string('name', 120);
            C::money($t, 'fee');
            $t->string('estimated_delay', 60)->default('24–48 h');
            /** Délai en jours utilisé pour calculer les dates estimées */
            $t->integer('estimated_days')->default(2);
            $t->boolean('is_active')->default(true)->index();
            $t->integer('sort_order')->default(0);
        });

        Schema::create('coupons', function (Blueprint $t) {
            C::base($t);
            $t->string('code', 40)->unique();
            $t->string('description', 255)->nullable();
            $t->enum('type', CouponType::values());
            /** Pourcentage (1-100) ou montant fixe en FCFA */
            $t->integer('value')->default(0);
            C::money($t, 'min_subtotal');
            C::money($t, 'max_discount', true);
            $t->text('category_ids')->nullable();
            $t->text('product_ids')->nullable();
            $t->integer('usage_limit')->nullable();
            $t->integer('usage_limit_per_customer')->nullable();
            $t->integer('used_count')->default(0);
            $t->dateTime('starts_at')->nullable();
            $t->dateTime('ends_at')->nullable();
            $t->boolean('is_active')->default(true)->index();
        });

        Schema::create('coupon_usages', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'coupon_id', 'coupons', 'cascade', false);
            $t->index('coupon_id');
            C::fk($t, 'order_id', null, nullable: false);
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('phone', 32)->index();
            C::money($t, 'discount');
        });

        Schema::create('orders', function (Blueprint $t) {
            C::base($t);
            $t->string('number', 20)->unique();
            C::fk($t, 'customer_id', 'customers');
            $t->index('customer_id');
            $t->string('contact_name', 120)->index();
            $t->string('contact_phone', 32)->index();
            $t->string('contact_email', 190)->nullable();
            $t->string('city', 80)->nullable();
            C::fk($t, 'zone_id', 'delivery_zones');
            $t->index('zone_id');
            $t->string('zone_name', 120)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('landmark', 255)->nullable();
            $t->string('instructions', 500)->nullable();
            $t->enum('delivery_mode', DeliveryMode::values())->default(DeliveryMode::HOME->value);
            $t->enum('delivery_grouping', DeliveryGrouping::values())->default(DeliveryGrouping::SPLIT->value);
            /** LOCAL, PREORDER ou MIXED */
            $t->string('order_type', 10)->default('LOCAL')->index();
            foreach (['subtotal', 'discount_total', 'shipping_total', 'total', 'deposit_amount', 'amount_paid', 'amount_refunded', 'balance_due'] as $m) {
                C::money($t, $m);
            }
            $t->enum('status', OrderStatus::values())->default(OrderStatus::PENDING_PAYMENT->value)->index();
            $t->enum('payment_status', PaymentStatus::values())->default(PaymentStatus::UNPAID->value)->index();
            $t->enum('payment_method', PaymentMethod::values())->index();
            C::fk($t, 'coupon_id', 'coupons');
            $t->string('coupon_code', 40)->nullable();
            $t->dateTime('preorder_terms_accepted_at', 3)->nullable();
            $t->text('preorder_terms_text')->nullable();
            $t->dateTime('cgv_accepted_at', 3)->nullable();
            $t->string('tracking_token', 64)->unique();
            $t->enum('source', OrderSource::values())->default(OrderSource::WEB->value)->index();
            $t->text('notes_internal')->nullable();
            $t->string('cancelled_reason', 500)->nullable();
            $t->dateTime('cancelled_at')->nullable();
            $t->dateTime('confirmed_at')->nullable();
            $t->dateTime('delivered_at')->nullable();
            $t->string('ip_address', 64)->nullable();
            $t->unsignedBigInteger('created_by_admin_id')->nullable();
        });

        Schema::create('arrivals', function (Blueprint $t) {
            C::base($t);
            $t->string('reference', 20)->unique();
            C::fk($t, 'supplier_id', 'suppliers');
            $t->index('supplier_id');
            $t->string('carrier', 120)->nullable();
            $t->string('carrier_tracking_number', 120)->nullable();
            $t->enum('current_step', ShipmentStep::values())->default(ShipmentStep::ORDERED_FROM_SUPPLIER->value)->index();
            $t->dateTime('shipped_at')->nullable();
            $t->dateTime('estimated_arrival_at')->nullable()->index();
            $t->dateTime('arrived_at')->nullable();
            $t->dateTime('received_at')->nullable();
            C::money($t, 'transport_cost');
            C::money($t, 'customs_cost');
            $t->text('notes')->nullable();
        });

        Schema::create('shipments', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'order_id', 'orders', 'cascade', false);
            $t->index('order_id');
            $t->enum('type', ItemType::values())->index();
            $t->enum('current_step', ShipmentStep::values())->nullable()->index();
            $t->dateTime('estimated_delivery_date')->nullable()->index();
            C::money($t, 'shipping_fee');
            $t->char('delivery_code', 4);
            C::fk($t, 'driver_id', 'admin_users');
            $t->index('driver_id');
            $t->dateTime('scheduled_for')->nullable()->index();
            $t->dateTime('delivered_at')->nullable();
            $t->boolean('is_delayed')->default(false)->index();
            $t->string('delay_reason', 500)->nullable();
        });

        Schema::create('order_items', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'order_id', 'orders', 'cascade', false);
            $t->index('order_id');
            C::fk($t, 'variant_id', 'product_variants');
            $t->index('variant_id');
            $t->unsignedBigInteger('product_id')->nullable();
            $t->string('product_name', 190);
            $t->string('reference', 60);
            $t->string('variant_name', 150)->nullable();
            $t->string('image_url', 500)->nullable();
            C::money($t, 'unit_price');
            $t->integer('quantity');
            C::money($t, 'line_total');
            C::money($t, 'deposit_amount');
            $t->integer('lead_time_days')->default(0);
            $t->enum('item_type', ItemType::values())->index();
            C::fk($t, 'shipment_id', 'shipments');
            $t->index('shipment_id');
            C::fk($t, 'arrival_id', 'arrivals');
            $t->index('arrival_id');
            $t->integer('received_qty')->default(0);
            /** Indique que la réservation de stock est active pour cette ligne */
            $t->boolean('stock_reserved')->default(false);
        });

        Schema::create('shipment_events', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'shipment_id', 'shipments', 'cascade', false);
            $t->index('shipment_id');
            $t->enum('step', ShipmentStep::values());
            $t->string('public_comment', 1000)->nullable();
            $t->text('internal_note')->nullable();
            $t->string('proof_url', 500)->nullable();
            C::fk($t, 'admin_user_id', 'admin_users');
            $t->unsignedBigInteger('arrival_id')->nullable();
            $t->dateTime('occurred_at', 3);
        });

        Schema::create('arrival_events', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'arrival_id', 'arrivals', 'cascade', false);
            $t->index('arrival_id');
            $t->enum('step', ShipmentStep::values());
            $t->string('public_comment', 1000)->nullable();
            $t->text('internal_note')->nullable();
            C::fk($t, 'admin_user_id', 'admin_users');
            $t->dateTime('occurred_at', 3);
        });

        Schema::create('arrival_documents', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'arrival_id', 'arrivals', 'cascade', false);
            $t->index('arrival_id');
            $t->string('name', 190);
            $t->string('url', 500);
            $t->string('mime_type', 80);
        });

        Schema::create('cash_collections', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'shipment_id', 'shipments', 'cascade', false);
            $t->index('shipment_id');
            C::fk($t, 'order_id', null, nullable: false);
            C::money($t, 'amount');
            /** CASH, AIRTEL_MONEY, MOOV_MONEY */
            $t->string('method', 20);
            C::fk($t, 'collected_by_id', 'admin_users');
            $t->dateTime('collected_at');
            $t->string('reference', 120)->nullable();
        });

        Schema::create('sequences', function (Blueprint $t) {
            C::base($t);
            $t->string('name', 40);
            $t->integer('year')->default(0);
            $t->integer('value')->default(0);
            $t->unique(['name', 'year']);
        });

        Schema::create('payments', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'order_id', 'orders', 'cascade', false);
            $t->index('order_id');
            /** airtel | moov | mock | manual | cash */
            $t->string('provider', 20)->index();
            $t->enum('method', PaymentMethod::values());
            C::money($t, 'amount');
            $t->string('currency', 3)->default('XAF');
            $t->enum('kind', PaymentKind::values());
            $t->enum('status', PaymentRecordStatus::values())->default(PaymentRecordStatus::PENDING->value)->index();
            $t->string('payer_phone', 32)->nullable();
            $t->string('provider_reference', 80)->unique();
            $t->string('provider_transaction_id', 120)->nullable()->index();
            $t->string('failure_reason', 500)->nullable();
            $t->string('instructions', 500)->nullable();
            $t->dateTime('expires_at')->nullable()->index();
            $t->dateTime('paid_at')->nullable()->index();
            $t->dateTime('last_checked_at')->nullable();
            $t->boolean('is_manual')->default(false);
            $t->string('manual_reference', 120)->nullable();
            /** Second paiement réussi sur une commande déjà soldée */
            $t->boolean('is_duplicate')->default(false)->index();
            C::money($t, 'refunded_amount');
            C::fk($t, 'admin_user_id', 'admin_users');
        });

        Schema::create('payment_events', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'payment_id', 'payments');
            $t->index('payment_id');
            $t->string('provider', 20)->index();
            $t->string('event_id', 190)->unique();
            $t->json('raw_payload');
            $t->boolean('signature_valid')->default(false);
            $t->string('outcome', 255)->nullable();
            $t->dateTime('processed_at')->nullable();
        });

        Schema::create('refunds', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'order_id', 'orders', 'cascade', false);
            $t->index('order_id');
            C::fk($t, 'payment_id', 'payments');
            C::money($t, 'amount');
            $t->string('reason', 500);
            $t->enum('status', RefundStatus::values())->default(RefundStatus::PENDING->value);
            $t->string('provider_reference', 120)->nullable();
            $t->boolean('is_manual')->default(false);
            $t->string('manual_reference', 120)->nullable();
            C::fk($t, 'admin_user_id', 'admin_users');
        });
    }

    public function down(): void
    {
        foreach ([
            'refunds', 'payment_events', 'payments', 'sequences', 'cash_collections', 'arrival_documents', 'arrival_events',
            'shipment_events', 'order_items', 'shipments', 'arrivals', 'orders', 'coupon_usages', 'coupons', 'delivery_zones',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
