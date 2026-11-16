<?php

namespace App\Services\Auth;

use App\Enums\SubjectType;
use App\Enums\TokenPurpose;
use App\Events\DomainEvent;
use App\Models\Customer;
use App\Models\Order;
use App\Models\PasswordReset;
use App\Services\TokenService;
use App\Support\Format;
use App\Support\RequestMeta;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerAuthService
{
    public function __construct(private readonly TokenService $tokens) {}

    public static function toProfile(Customer $c): array
    {
        return [
            'id' => $c->id,
            'fullName' => $c->full_name,
            'phone' => $c->phone,
            'email' => $c->email,
            'phoneVerified' => (bool) $c->phone_verified_at,
            'newsletterConsent' => (bool) $c->newsletter_consent,
        ];
    }

    private function requirePhone(string $input): string
    {
        return Format::normalizeGabonPhone($input) ?? throw new HttpException(400, 'Numéro gabonais invalide (ex. +241 77 12 34 56)');
    }

    public function register(array $dto, RequestMeta $meta): array
    {
        $phone = $this->requirePhone($dto['phone']);
        $email = ! empty($dto['email']) ? strtolower(trim($dto['email'])) : null;
        $existing = Customer::where('phone', $phone)->first();
        if ($existing?->password_hash) {
            throw new HttpException(409, 'Un compte existe déjà avec ce numéro. Connectez-vous.');
        }
        if ($email) {
            $taken = Customer::where('email', $email)->where('is_guest', false)
                ->when($existing, fn ($q) => $q->where('id', '<>', $existing->id))->exists();
            if ($taken) {
                throw new HttpException(409, 'Cette adresse e-mail est déjà utilisée');
            }
        }
        $now = now();
        $customer = $existing ?? new Customer(['phone' => $phone]);
        $consent = $dto['newsletterConsent'] ?? null;
        $customer->fill([
            'full_name' => trim($dto['fullName']),
            'email' => $email ?? $customer->email,
            'password_hash' => AdminAuthService::hashPassword($dto['password']),
            'is_guest' => false,
            'registered_at' => $now,
            'last_login_at' => $now,
            'newsletter_consent' => $consent ?? $customer->newsletter_consent ?? false,
            'newsletter_consent_at' => $consent ? $now : $customer->newsletter_consent_at,
        ])->save();
        try {
            $this->requestPhoneVerification($customer->id);
        } catch (\Throwable) {
        }

        return $this->issue($customer, $meta);
    }

    /** Création de compte depuis le tunnel de commande. */
    public function upgradeGuest(int $customerId, string $password, ?string $email = null): void
    {
        $c = Customer::find($customerId);
        if (! $c || $c->password_hash) {
            return;
        }
        $c->update([
            'password_hash' => AdminAuthService::hashPassword($password),
            'is_guest' => false,
            'registered_at' => now(),
            'email' => $email ?? $c->email,
        ]);
        try {
            $this->requestPhoneVerification($customerId);
        } catch (\Throwable) {
        }
    }

    public function login(string $identifier, string $password, RequestMeta $meta): array
    {
        $id = strtolower(trim($identifier));
        $q = Customer::whereNotNull('password_hash');
        str_contains($id, '@') ? $q->where('email', $id) : $q->where('phone', Format::normalizeGabonPhone($id) ?? '__invalid__');
        $customer = $q->first();
        if (! $customer || ! AdminAuthService::verifyPassword($password, $customer->password_hash)) {
            throw new HttpException(401, 'Identifiants incorrects');
        }
        $customer->update(['last_login_at' => now()]);

        return $this->issue($customer, $meta);
    }

    private function issue(Customer $customer, RequestMeta $meta): array
    {
        return [
            'accessToken' => $this->tokens->signCustomerAccess($customer->id),
            'refreshToken' => $this->tokens->issueRefresh(SubjectType::CUSTOMER, $customer->id, $meta),
            'customer' => self::toProfile($customer),
        ];
    }

    public function refresh(?string $raw, RequestMeta $meta): array
    {
        ['subjectId' => $id, 'raw' => $newRaw] = $this->tokens->rotate($raw, SubjectType::CUSTOMER, $meta);
        $customer = Customer::find($id) ?? throw new HttpException(401, 'Compte introuvable');

        return ['accessToken' => $this->tokens->signCustomerAccess($customer->id), 'refreshToken' => $newRaw, 'customer' => self::toProfile($customer)];
    }

    public function forgotPassword(string $identifier): void
    {
        $id = strtolower(trim($identifier));
        $customer = Customer::where('is_guest', false)
            ->where(str_contains($id, '@') ? 'email' : 'phone', str_contains($id, '@') ? $id : (Format::normalizeGabonPhone($id) ?? '__invalid__'))
            ->first();
        if (! $customer) {
            return;
        }
        $raw = Format::randomToken(32);
        PasswordReset::create([
            'subject_type' => SubjectType::CUSTOMER->value,
            'purpose' => TokenPurpose::RESET_PASSWORD->value,
            'customer_id' => $customer->id,
            'token_hash' => Format::sha256($raw),
            'expires_at' => now()->addHour(),
        ]);
        DomainEvent::emit(DomainEvent::AUTH_PASSWORD_RESET, [
            'name' => $customer->full_name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'url' => config('amoria.web_public_url')."/compte/reinitialiser?token={$raw}",
        ]);
    }

    public function resetPassword(string $raw, string $password): void
    {
        $reset = PasswordReset::where('token_hash', Format::sha256($raw))
            ->where('purpose', TokenPurpose::RESET_PASSWORD->value)
            ->whereNull('used_at')->where('expires_at', '>', now())->first();
        if (! $reset?->customer_id) {
            throw new HttpException(400, 'Lien invalide ou expiré');
        }
        Customer::whereKey($reset->customer_id)->update(['password_hash' => AdminAuthService::hashPassword($password)]);
        $reset->update(['used_at' => now()]);
        $this->tokens->revokeAll(SubjectType::CUSTOMER, $reset->customer_id);
    }

    public function requestPhoneVerification(int $customerId): void
    {
        $customer = Customer::findOrFail($customerId);
        if ($customer->phone_verified_at) {
            return;
        }
        $code = Format::randomDigits(6);
        PasswordReset::where('customer_id', $customerId)->where('purpose', TokenPurpose::VERIFY_PHONE->value)->whereNull('used_at')->update(['used_at' => now()]);
        PasswordReset::create([
            'subject_type' => SubjectType::CUSTOMER->value,
            'purpose' => TokenPurpose::VERIFY_PHONE->value,
            'customer_id' => $customerId,
            'token_hash' => Format::sha256("{$customerId}:{$code}"),
            'expires_at' => now()->addMinutes(10),
        ]);
        DomainEvent::emit(DomainEvent::AUTH_PHONE_VERIFICATION, ['phone' => $customer->phone, 'code' => $code]);
    }

    /** Vérifie le téléphone puis rattache les commandes passées en invité avec ce numéro. */
    public function confirmPhoneVerification(int $customerId, string $code): array
    {
        $reset = PasswordReset::where('customer_id', $customerId)->where('purpose', TokenPurpose::VERIFY_PHONE->value)
            ->whereNull('used_at')->where('expires_at', '>', now())->orderByDesc('created_at')->first();
        if (! $reset) {
            throw new HttpException(400, 'Code expiré, demandez-en un nouveau');
        }
        if ($reset->attempts >= 5) {
            throw new HttpException(400, 'Trop de tentatives, demandez un nouveau code');
        }
        if (! hash_equals($reset->token_hash, Format::sha256("{$customerId}:{$code}"))) {
            $reset->increment('attempts');
            throw new HttpException(400, 'Code incorrect');
        }
        $reset->update(['used_at' => now()]);
        $customer = Customer::findOrFail($customerId);
        $customer->update(['phone_verified_at' => now()]);
        Order::where('contact_phone', $customer->phone)->whereNull('customer_id')->update(['customer_id' => $customerId]);

        return self::toProfile($customer);
    }
}
