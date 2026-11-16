<?php

use App\Enums\SubjectType;
use App\Enums\TokenPurpose;
use App\Support\Schema\Columns as C;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $t) {
            C::base($t);
            $t->string('email', 190)->unique();
            $t->string('password_hash', 255);
            $t->string('full_name', 120);
            $t->string('phone', 20)->nullable();
            $t->boolean('is_active')->default(true)->index();
            $t->string('two_factor_secret', 255)->nullable();
            $t->boolean('two_factor_enabled')->default(false);
            $t->dateTime('last_login_at')->nullable();
            $t->integer('failed_attempts')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('deleted_at', 6)->nullable();
            /** Rôles définis dans le code (App\Support\Domain), séparés par des virgules. */
            $t->text('role_codes');
        });

        Schema::create('customers', function (Blueprint $t) {
            C::base($t);
            $t->string('full_name', 120)->index();
            $t->string('phone', 32)->unique();
            $t->string('email', 190)->nullable()->index();
            $t->string('password_hash', 255)->nullable();
            $t->dateTime('phone_verified_at')->nullable();
            $t->dateTime('registered_at')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->boolean('newsletter_consent')->default(false)->index();
            $t->dateTime('newsletter_consent_at')->nullable();
            $t->text('notes')->nullable();
            $t->boolean('is_guest')->default(true)->index();
            $t->dateTime('deleted_at', 6)->nullable();
        });

        Schema::create('refresh_tokens', function (Blueprint $t) {
            C::base($t);
            $t->enum('subject_type', SubjectType::values());
            $t->unsignedBigInteger('admin_user_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->string('token_hash', 128)->unique();
            $t->string('family_id', 64)->index();
            $t->dateTime('expires_at');
            $t->dateTime('revoked_at')->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->string('ip', 64)->nullable();
            $t->index(['subject_type', 'admin_user_id']);
            $t->index(['subject_type', 'customer_id']);
        });

        Schema::create('password_resets', function (Blueprint $t) {
            C::base($t);
            $t->enum('subject_type', SubjectType::values());
            $t->enum('purpose', TokenPurpose::values())->default(TokenPurpose::RESET_PASSWORD->value);
            $t->unsignedBigInteger('admin_user_id')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable()->index();
            $t->string('token_hash', 128)->index();
            $t->dateTime('expires_at');
            $t->dateTime('used_at')->nullable();
            $t->integer('attempts')->default(0);
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            C::base($t);
            C::fk($t, 'admin_user_id', 'admin_users');
            $t->index('admin_user_id');
            $t->string('action', 80)->index();
            $t->string('entity_type', 60);
            $t->string('entity_id', 64)->nullable();
            $t->json('before')->nullable();
            $t->json('after')->nullable();
            $t->string('ip', 64)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->index(['entity_type', 'entity_id']);
        });

        Schema::create('newsletter_subscribers', function (Blueprint $t) {
            C::base($t);
            $t->string('email', 190)->nullable()->unique();
            $t->string('phone', 32)->nullable()->unique();
            $t->dateTime('consent_at');
            $t->string('source', 40)->default('web');
            $t->dateTime('unsubscribed_at')->nullable();
        });
    }

    public function down(): void
    {
        foreach (['newsletter_subscribers', 'audit_logs', 'password_resets', 'refresh_tokens', 'customers', 'admin_users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
