<?php

namespace App\Services;

use App\Enums\SubjectType;
use App\Models\RefreshToken;
use App\Support\Format;
use App\Support\RequestMeta;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Jetons d'accès JWT (admin / client / temporaire) et jetons de rafraîchissement en cookie HttpOnly. */
class TokenService
{
    public const ADMIN_RT_COOKIE = 'amoria_admin_rt';

    public const CUSTOMER_RT_COOKIE = 'amoria_customer_rt';

    private const COOKIE_PATHS = [
        'ADMIN' => '/api/v1/admin/auth',
        'CUSTOMER' => '/api/v1/auth/customer',
    ];

    private function sign(array $payload, string $secret, int $ttl): string
    {
        $now = time();

        return JWT::encode($payload + ['iat' => $now, 'exp' => $now + $ttl], $secret, 'HS256');
    }

    /** @return array<string, mixed> */
    private function decode(string $token, string $secret): array
    {
        return json_decode(json_encode(JWT::decode($token, new Key($secret, 'HS256'))), true);
    }

    public function signAdminAccess(int $id, string $email, array $roles, array $perms): string
    {
        return $this->sign(
            ['sub' => $id, 'email' => $email, 'roles' => $roles, 'perms' => $perms, 'typ' => 'admin'],
            config('amoria.jwt.admin_secret'),
            config('amoria.jwt.access_ttl'),
        );
    }

    public function signCustomerAccess(int $customerId): string
    {
        return $this->sign(['sub' => $customerId, 'typ' => 'customer'], config('amoria.jwt.customer_secret'), config('amoria.jwt.access_ttl'));
    }

    /** @return array{sub: int, typ: string, email: string, roles: list<string>, perms: list<string>}|null */
    public function verifyAdmin(string $token): ?array
    {
        try {
            $p = $this->decode($token, config('amoria.jwt.admin_secret'));

            return ($p['typ'] ?? null) === 'admin' ? $p : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{sub: int, typ: string}|null */
    public function verifyCustomer(string $token): ?array
    {
        try {
            $p = $this->decode($token, config('amoria.jwt.customer_secret'));

            return ($p['typ'] ?? null) === 'customer' ? $p : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Jeton temporaire signé (2FA, etc.). */
    public function signTemp(array $payload, int $ttl): string
    {
        return $this->sign($payload, config('amoria.jwt.temp_secret'), $ttl);
    }

    public function verifyTemp(string $token, string $purpose): array
    {
        try {
            $p = $this->decode($token, config('amoria.jwt.temp_secret'));
            if (($p['purpose'] ?? null) !== $purpose) {
                throw new \RuntimeException('purpose');
            }

            return $p;
        } catch (\Throwable) {
            throw new HttpException(401, 'Session de connexion expirée, veuillez recommencer');
        }
    }

    public function issueRefresh(SubjectType $type, int $subjectId, RequestMeta $meta, ?string $familyId = null): string
    {
        $raw = Format::randomToken(48);
        RefreshToken::create([
            'subject_type' => $type->value,
            'admin_user_id' => $type === SubjectType::ADMIN ? $subjectId : null,
            'customer_id' => $type === SubjectType::CUSTOMER ? $subjectId : null,
            'token_hash' => Format::sha256($raw),
            'family_id' => $familyId ?? Format::randomToken(24),
            'expires_at' => now()->addDays(config('amoria.refresh_ttl_days')),
            'user_agent' => $meta->userAgent,
            'ip' => $meta->ip,
        ]);

        return $raw;
    }

    /**
     * Rotation : l'ancien jeton est révoqué ; la réutilisation d'un jeton révoqué révoque toute la famille.
     *
     * @return array{subjectId: int, raw: string}
     */
    public function rotate(?string $raw, SubjectType $type, RequestMeta $meta): array
    {
        if (! $raw) {
            throw new HttpException(401, 'Session expirée');
        }
        $token = RefreshToken::where('token_hash', Format::sha256($raw))->where('subject_type', $type->value)->first();
        if (! $token) {
            throw new HttpException(401, 'Session expirée');
        }
        if ($token->revoked_at) {
            RefreshToken::where('family_id', $token->family_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            throw new HttpException(401, 'Session révoquée, veuillez vous reconnecter');
        }
        if ($token->expires_at->isPast()) {
            throw new HttpException(401, 'Session expirée');
        }
        $token->update(['revoked_at' => now()]);
        $subjectId = $type === SubjectType::ADMIN ? $token->admin_user_id : $token->customer_id;

        return ['subjectId' => (int) $subjectId, 'raw' => $this->issueRefresh($type, (int) $subjectId, $meta, $token->family_id)];
    }

    public function revoke(?string $raw): void
    {
        if ($raw) {
            RefreshToken::where('token_hash', Format::sha256($raw))->whereNull('revoked_at')->update(['revoked_at' => now()]);
        }
    }

    public function revokeAll(SubjectType $type, int $subjectId): void
    {
        RefreshToken::where('subject_type', $type->value)
            ->where($type === SubjectType::ADMIN ? 'admin_user_id' : 'customer_id', $subjectId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }

    public function revokeById(int $id): void
    {
        RefreshToken::where('id', $id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function listActive(SubjectType $type, int $subjectId): array
    {
        return RefreshToken::where('subject_type', $type->value)
            ->where($type === SubjectType::ADMIN ? 'admin_user_id' : 'customer_id', $subjectId)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', DB::raw('NOW()'))
            ->orderByDesc('created_at')
            ->get(['id', 'created_at', 'expires_at', 'user_agent', 'ip'])
            ->toArray();
    }

    public function makeCookie(SubjectType $type, string $raw): Cookie
    {
        return Cookie::create(
            $type === SubjectType::ADMIN ? self::ADMIN_RT_COOKIE : self::CUSTOMER_RT_COOKIE,
            $raw,
            now()->addDays(config('amoria.refresh_ttl_days')),
            self::COOKIE_PATHS[$type->value],
            config('amoria.cookie_domain'),
            config('amoria.cookie_secure'),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    public function forgetCookie(SubjectType $type): Cookie
    {
        return Cookie::create(
            $type === SubjectType::ADMIN ? self::ADMIN_RT_COOKIE : self::CUSTOMER_RT_COOKIE,
            null,
            1,
            self::COOKIE_PATHS[$type->value],
            config('amoria.cookie_domain'),
            config('amoria.cookie_secure'),
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }
}
