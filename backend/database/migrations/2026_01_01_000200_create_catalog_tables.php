<?php

use App\Enums\AttributeType;
use App\Enums\AvailabilityMode;
use App\Enums\ProductStatus;
use App\Enums\ReviewStatus;
use App\Enums\StockMovementType;
use App\Support\Schema\Columns as C;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            C::base($t);
            $t->string('name', 150)->index();
            $t->string('country', 80)->default('Chine');
            $t->string('contact_name', 120)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('wechat', 80)->nullable();
            $t->string('email', 190)->nullable();
            $t->integer('usual_lead_time_days')->default(14);
            $t->text('notes')->nullable();
            $t->boolean('is_active')->default(true);
        });

        Schema::create('categories', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'parent_id', 'categories');
            $t->index('parent_id');
            $t->string('name', 120);
            $t->string('slug', 190)->unique();
            $t->string('code', 6)->unique();
            $t->text('description')->nullable();
            $t->string('image_url', 500)->nullable();
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true)->index();
            $t->string('seo_title', 190)->nullable();
            $t->string('seo_description', 320)->nullable();
            $t->dateTime('deleted_at', 6)->nullable();
        });

        Schema::create('collections', function (Blueprint $t) {
            C::base($t);
            $t->string('name', 120);
            $t->string('slug', 190)->unique();
            $t->text('description')->nullable();
            $t->string('image_url', 500)->nullable();
            $t->dateTime('starts_at')->nullable()->index();
            $t->dateTime('ends_at')->nullable()->index();
            $t->boolean('is_active')->default(true)->index();
            $t->boolean('is_featured')->default(false);
            $t->integer('sort_order')->default(0);
            $t->string('seo_title', 190)->nullable();
            $t->string('seo_description', 320)->nullable();
        });

        Schema::create('attributes', function (Blueprint $t) {
            C::base($t);
            $t->string('name', 80);
            $t->string('code', 60)->unique();
            $t->enum('type', AttributeType::values())->default(AttributeType::OTHER->value);
            $t->integer('sort_order')->default(0);
        });

        Schema::create('attribute_values', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'attribute_id', 'attributes', 'cascade', false);
            $t->index('attribute_id');
            $t->string('value', 80)->index();
            $t->string('hex_color', 9)->nullable();
            $t->integer('sort_order')->default(0);
        });

        Schema::create('products', function (Blueprint $t) {
            C::base($t);
            $t->string('name', 190);
            $t->string('slug', 190)->unique();
            $t->string('reference', 40)->unique();
            C::fk($t, 'category_id', 'categories');
            $t->index('category_id');
            $t->string('short_description', 500)->nullable();
            $t->mediumText('description')->nullable();
            $t->string('dimensions', 190)->nullable();
            $t->string('material', 120)->nullable()->index();
            $t->text('colors')->nullable();
            $t->text('care_instructions')->nullable();
            $t->integer('weight')->nullable();
            C::money($t, 'base_price');
            C::money($t, 'compare_at_price', true);
            C::money($t, 'cost_price', true);
            C::money($t, 'current_price')->index();
            $t->boolean('on_sale')->default(false)->index();
            $t->enum('availability_mode', AvailabilityMode::values())->default(AvailabilityMode::IN_STOCK->value);
            $t->enum('computed_availability', AvailabilityMode::values())->default(AvailabilityMode::UNAVAILABLE->value)->index();
            $t->integer('lead_time_days')->default(14);
            $t->unsignedTinyInteger('deposit_percent')->nullable();
            $t->boolean('full_payment_required')->default(false);
            $t->boolean('auto_preorder_when_out_of_stock')->default(false);
            $t->integer('max_preorder_qty')->nullable();
            C::fk($t, 'supplier_id', 'suppliers');
            $t->index('supplier_id');
            $t->boolean('is_featured')->default(false)->index();
            $t->boolean('is_new')->default(false)->index();
            $t->boolean('is_best_seller')->default(false)->index();
            $t->enum('status', ProductStatus::values())->default(ProductStatus::DRAFT->value)->index();
            $t->dateTime('published_at')->nullable();
            $t->string('seo_title', 190)->nullable();
            $t->string('seo_description', 320)->nullable();
            $t->integer('sales_count')->default(0)->index();
            $t->dateTime('deleted_at', 6)->nullable();
            $t->fullText('name');
        });

        Schema::create('product_variants', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'product_id', 'products', 'cascade', false);
            $t->index('product_id');
            $t->string('sku', 60)->unique();
            $t->string('name', 150)->default('Standard');
            C::money($t, 'price');
            C::money($t, 'compare_at_price', true);
            C::money($t, 'promo_price', true);
            $t->dateTime('promo_starts_at')->nullable();
            $t->dateTime('promo_ends_at')->nullable();
            C::money($t, 'cost_price', true);
            $t->integer('stock_on_hand')->default(0);
            $t->integer('stock_reserved')->default(0);
            $t->integer('low_stock_threshold')->default(3);
            $t->enum('availability_mode', AvailabilityMode::values())->nullable();
            $t->boolean('auto_switched')->default(false);
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true)->index();
            $t->integer('sort_order')->default(0);
        });

        Schema::create('product_images', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'product_id', 'products', 'cascade', false);
            $t->index('product_id');
            C::fk($t, 'variant_id', 'product_variants');
            $t->index('variant_id');
            $t->string('url', 500);
            $t->string('medium_url', 500);
            $t->string('thumb_url', 500);
            $t->string('alt', 255)->default('');
            $t->integer('sort_order')->default(0);
            $t->boolean('is_main')->default(false);
        });

        Schema::create('product_collections', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('collection_id');
            $t->primary(['product_id', 'collection_id']);
            $t->index('collection_id');
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete()->cascadeOnUpdate();
            $t->foreign('collection_id')->references('id')->on('collections')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('related_products', function (Blueprint $t) {
            $t->unsignedBigInteger('product_id');
            $t->unsignedBigInteger('related_product_id');
            $t->primary(['product_id', 'related_product_id']);
            $t->index('related_product_id');
            $t->foreign('product_id')->references('id')->on('products')->cascadeOnDelete()->cascadeOnUpdate();
            $t->foreign('related_product_id')->references('id')->on('products')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('variant_attribute_values', function (Blueprint $t) {
            $t->unsignedBigInteger('variant_id');
            $t->unsignedBigInteger('attribute_value_id');
            $t->primary(['variant_id', 'attribute_value_id']);
            $t->index('attribute_value_id');
            $t->foreign('variant_id')->references('id')->on('product_variants')->cascadeOnDelete()->cascadeOnUpdate();
            $t->foreign('attribute_value_id')->references('id')->on('attribute_values')->cascadeOnDelete()->cascadeOnUpdate();
        });

        Schema::create('stock_movements', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'variant_id', 'product_variants', 'cascade', false);
            $t->index('variant_id');
            $t->enum('type', StockMovementType::values())->index();
            /** Quantité signée : positive = entrée, négative = sortie */
            $t->integer('quantity');
            $t->string('reason', 255);
            $t->integer('on_hand_after')->default(0);
            $t->integer('reserved_after')->default(0);
            C::fk($t, 'order_id');
            C::fk($t, 'arrival_id');
            C::fk($t, 'admin_user_id', 'admin_users');
        });

        Schema::create('back_in_stock_alerts', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'variant_id', 'product_variants', 'cascade', false);
            $t->index('variant_id');
            $t->string('email', 190)->nullable();
            $t->string('phone', 32)->nullable();
            $t->dateTime('notified_at')->nullable()->index();
        });

        Schema::create('reviews', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'product_id', 'products', 'cascade', false);
            $t->index('product_id');
            C::fk($t, 'customer_id', 'customers');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->string('author_name', 120);
            $t->unsignedTinyInteger('rating');
            $t->text('comment')->nullable();
            $t->enum('status', ReviewStatus::values())->default(ReviewStatus::PENDING->value)->index();
        });

        Schema::create('customer_addresses', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'customer_id', 'customers', 'cascade', false);
            $t->index('customer_id');
            $t->string('label', 60)->nullable();
            $t->string('city', 80);
            $t->unsignedBigInteger('zone_id')->nullable();
            $t->string('zone_name', 120)->nullable();
            $t->string('district', 120)->nullable();
            $t->string('landmark', 255);
            $t->string('instructions', 500)->nullable();
            $t->boolean('is_default')->default(false);
        });

        Schema::create('wishlists', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'customer_id', 'customers', 'cascade', false);
            $t->index('customer_id');
            C::fk($t, 'product_id', 'products', 'cascade', false);
            $t->unique(['customer_id', 'product_id']);
        });

        Schema::create('carts', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'customer_id', 'customers', 'cascade', false);
            $t->unique('customer_id');
        });

        Schema::create('cart_items', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'cart_id', 'carts', 'cascade', false);
            C::fk($t, 'variant_id', 'product_variants', 'cascade', false);
            $t->integer('quantity');
            $t->unique(['cart_id', 'variant_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'cart_items', 'carts', 'wishlists', 'customer_addresses', 'reviews', 'back_in_stock_alerts', 'stock_movements',
            'variant_attribute_values', 'related_products', 'product_collections', 'product_images', 'product_variants', 'products',
            'attribute_values', 'attributes', 'collections', 'categories', 'suppliers',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
