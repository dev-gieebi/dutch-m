<?php

use App\Enums\AdminNotificationType;
use App\Enums\ConversationStatus;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationStatus;
use App\Enums\SenderType;
use App\Support\Schema\Columns as C;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'customer_id', 'customers');
            $t->index('customer_id');
            $t->string('visitor_name', 120);
            $t->string('visitor_phone', 32)->index();
            $t->string('visitor_email', 190)->nullable();
            $t->string('subject', 150)->nullable();
            $t->string('public_token', 64)->unique();
            C::fk($t, 'product_id', 'products');
            $t->index('product_id');
            C::fk($t, 'order_id', 'orders');
            $t->index('order_id');
            $t->enum('status', ConversationStatus::values())->default(ConversationStatus::NEW->value)->index();
            C::fk($t, 'assigned_to_id', 'admin_users');
            $t->index('assigned_to_id');
            $t->dateTime('last_message_at', 3)->nullable()->index();
            $t->integer('unread_for_admin')->default(0);
            $t->integer('unread_for_customer')->default(0);
            $t->text('internal_note')->nullable();
        });

        Schema::create('messages', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'conversation_id', 'conversations', 'cascade', false);
            $t->index('conversation_id');
            $t->enum('sender_type', SenderType::values());
            C::fk($t, 'admin_user_id', 'admin_users');
            $t->text('body');
            $t->string('attachment_url', 500)->nullable();
            /** Note interne non visible par le client */
            $t->boolean('is_internal')->default(false);
            $t->dateTime('read_at')->nullable();
        });

        Schema::create('quick_replies', function (Blueprint $t) {
            C::base($t);
            $t->string('title', 120);
            $t->text('body');
            $t->integer('sort_order')->default(0);
        });

        Schema::create('notifications', function (Blueprint $t) {
            C::base($t);
            $t->enum('channel', NotificationChannelType::values())->index();
            $t->string('recipient', 190);
            $t->string('template', 60);
            $t->json('payload')->nullable();
            $t->string('subject', 255)->nullable();
            $t->mediumText('body')->nullable();
            $t->enum('status', NotificationStatus::values())->default(NotificationStatus::PENDING->value)->index();
            $t->integer('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->unsignedBigInteger('order_id')->nullable()->index();
        });

        Schema::create('notification_templates', function (Blueprint $t) {
            C::base($t);
            $t->string('code', 60);
            $t->enum('channel', NotificationChannelType::values());
            $t->string('name', 120);
            $t->string('subject', 255)->nullable();
            $t->mediumText('body');
            $t->boolean('is_active')->default(true);
            $t->unique(['code', 'channel']);
        });

        Schema::create('admin_notifications', function (Blueprint $t) {
            C::base($t);
            /** null = notification destinée à toute l'équipe */
            $t->unsignedBigInteger('admin_user_id')->nullable()->index();
            $t->enum('type', AdminNotificationType::values())->index();
            $t->string('title', 190);
            $t->string('link', 255)->nullable();
            $t->dateTime('read_at')->nullable()->index();
        });

        Schema::create('banners', function (Blueprint $t) {
            C::base($t);
            $t->string('title', 150);
            $t->string('subtitle', 255)->nullable();
            $t->string('image_desktop_url', 500);
            $t->string('image_mobile_url', 500)->nullable();
            $t->string('link_url', 500)->nullable();
            $t->string('cta_label', 60)->nullable();
            $t->dateTime('starts_at')->nullable()->index();
            $t->dateTime('ends_at')->nullable()->index();
            $t->boolean('is_active')->default(true)->index();
            $t->integer('sort_order')->default(0);
        });

        Schema::create('pages', function (Blueprint $t) {
            C::base($t);
            $t->string('slug', 120)->unique();
            $t->string('title', 190);
            $t->mediumText('content');
            $t->string('seo_title', 190)->nullable();
            $t->string('seo_description', 320)->nullable();
            $t->boolean('is_published')->default(true);
        });

        Schema::create('faq_categories', function (Blueprint $t) {
            C::base($t);
            $t->string('name', 120);
            $t->integer('sort_order')->default(0);
        });

        Schema::create('faqs', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'category_id', 'faq_categories', 'cascade', false);
            $t->index('category_id');
            $t->string('question', 255);
            $t->text('answer');
            $t->integer('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
        });

        Schema::create('settings', function (Blueprint $t) {
            C::base($t);
            $t->string('key', 80)->unique();
            $t->json('value')->nullable();
        });
    }

    public function down(): void
    {
        foreach ([
            'settings', 'faqs', 'faq_categories', 'pages', 'banners', 'admin_notifications', 'notification_templates',
            'notifications', 'quick_replies', 'messages', 'conversations',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
