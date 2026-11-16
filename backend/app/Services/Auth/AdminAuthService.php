<?php

namespace App\Services\Auth;

use App\Enums\SubjectType;
use App\Models\AdminUser;
use App\Services\AuditService;
use App\Services\TokenService;
use App\Support\Domain;
use App\Support\RequestMeta;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminAuthService
{
    private const MAX_FAILED = 5;

    private const LOCK_MINUTES = 15;

    private Google2FA $totp;

    public function __construct(
        private readonly TokenService $tokens,
        private readonly AuditService $audit,
    ) {
        $this->totp = new Google2FA;
        $this->totp->setWindow(1);
    }

    public static function toProfile(AdminUser $user): array
    {
        $roles = $user->role_codes ?? [];

        return [
            'id' => $user->id,
            'email' => $user->email,
            'fullName' => $user->full_name,
            'roles' => $roles,
            'permissions' => Domain::permissionsFor($roles),
            'twoFactorEnabled' => (bool) $user->two_factor_enabled,
        ];
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public static function verifyPassword(string $password, ?string $hash): bool
    {
        return $hash !== null && $hash !== '' && password_verify($password, $hash);
    }

    private function registerFailure(AdminUser $user, RequestMeta $meta, string $reason): never
    {
        $failed = $user->failed_attempts + 1;
        $lock = $failed >= self::MAX_FAILED;
        $user->update([
            'failed_attempts' => $lock ? 0 : $failed,
            'locked_until' => $lock ? now()->addMinutes(self::LOCK_MINUTES) : $user->locked_until,
        ]);
        $this->audit->log($user->id, $lock ? 'auth.locked' : 'auth.failed', 'admin_user', $user->id, null, ['reason' => $reason], $meta);
        throw new HttpException(401, $lock ? "Trop d'échecs : compte verrouillé pendant ".self::LOCK_MINUTES.' minutes' : 'Identifiants ou code invalides');
    }

    private function assertNotLocked(AdminUser $user): void
    {
        if ($user->locked_until && $user->locked_until->isFuture()) {
            $minutes = (int) ceil(now()->diffInSeconds($user->locked_until) / 60);
            throw new HttpException(401, "Compte temporairement verrouillé. Réessayez dans {$minutes} min.");
        }
    }

    public function login(string $email, string $password, RequestMeta $meta): array
    {
        $user = AdminUser::where('email', strtolower(trim($email)))->first();
        if (! $user || ! $user->is_active) {
            throw new HttpException(401, 'Identifiants ou code invalides');
        }
        $this->assertNotLocked($user);
        if (! self::verifyPassword($password, $user->password_hash)) {
            $this->registerFailure($user, $meta, 'password');
        }
        if ($user->two_factor_enabled) {
            return ['step' => '2FA', 'tempToken' => $this->tokens->signTemp(['sub' => $user->id, 'purpose' => '2fa'], 300)];
        }
        if (array_intersect($user->role_codes ?? [], Domain::ROLES_REQUIRING_2FA)) {
            return ['step' => '2FA_SETUP', 'tempToken' => $this->tokens->signTemp(['sub' => $user->id, 'purpose' => '2fa'], 600)];
        }

        return $this->completeLogin($user, $meta);
    }

    public function setupWithTempToken(string $tempToken): array
    {
        $p = $this->tokens->verifyTemp($tempToken, '2fa');
        $user = AdminUser::find($p['sub']);
        if (! $user || ! $user->is_active) {
            throw new HttpException(401, 'Unauthorized');
        }
        if ($user->two_factor_enabled) {
            throw new HttpException(400, 'La double authentification est déjà activée');
        }

        return $this->generateSecret($user);
    }

    public function setupForCurrent(int $adminId): array
    {
        $user = AdminUser::find($adminId) ?? throw new HttpException(401, 'Unauthorized');
        if ($user->two_factor_enabled) {
            throw new HttpException(400, 'La double authentification est déjà activée');
        }

        return $this->generateSecret($user);
    }

    private function generateSecret(AdminUser $user): array
    {
        $secret = $this->totp->generateSecretKey();
        $user->update(['two_factor_secret' => $secret]);
        $otpauthUrl = $this->totp->getQRCodeUrl(config('amoria.totp_issuer'), $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($otpauthUrl);

        return ['secret' => $secret, 'otpauthUrl' => $otpauthUrl, 'qrCodeDataUrl' => 'data:image/svg+xml;base64,'.base64_encode($svg)];
    }

    public function verify2fa(string $tempToken, string $code, RequestMeta $meta): array
    {
        $p = $this->tokens->verifyTemp($tempToken, '2fa');
        $user = AdminUser::find($p['sub']);
        if (! $user || ! $user->is_active) {
            throw new HttpException(401, 'Unauthorized');
        }
        $this->assertNotLocked($user);
        if (! $user->two_factor_secret) {
            throw new HttpException(400, "Configurez d'abord la double authentification");
        }
        if (! $this->totp->verifyKey($user->two_factor_secret, $code)) {
            $this->registerFailure($user, $meta, 'totp');
        }
        if (! $user->two_factor_enabled) {
            $user->update(['two_factor_enabled' => true]);
            $this->audit->log($user->id, 'auth.2fa_enabled', 'admin_user', $user->id, null, null, $meta);
        }

        return $this->completeLogin($user, $meta);
    }

    public function enableForCurrent(int $adminId, string $code, RequestMeta $meta): array
    {
        $user = AdminUser::find($adminId);
        if (! $user?->two_factor_secret) {
            throw new HttpException(400, "Générez d'abord un secret");
        }
        if (! $this->totp->verifyKey($user->two_factor_secret, $code)) {
            throw new HttpException(400, 'Code invalide');
        }
        $user->update(['two_factor_enabled' => true]);
        $this->audit->log($user->id, 'auth.2fa_enabled', 'admin_user', $user->id, null, null, $meta);

        return ['twoFactorEnabled' => true];
    }

    private function completeLogin(AdminUser $user, RequestMeta $meta): array
    {
        $user->update(['failed_attempts' => 0, 'locked_until' => null, 'last_login_at' => now()]);
        $this->audit->log($user->id, 'auth.login', 'admin_user', $user->id, null, null, $meta);
        $profile = self::toProfile($user);

        return [
            'step' => 'DONE',
            'accessToken' => $this->signAccess($profile),
            'refreshToken' => $this->tokens->issueRefresh(SubjectType::ADMIN, $user->id, $meta),
            'user' => $profile,
        ];
    }

    private function signAccess(array $p): string
    {
        return $this->tokens->signAdminAccess($p['id'], $p['email'], $p['roles'], $p['permissions']);
    }

    public function refresh(?string $raw, RequestMeta $meta): array
    {
        ['subjectId' => $id, 'raw' => $newRaw] = $this->tokens->rotate($raw, SubjectType::ADMIN, $meta);
        $user = AdminUser::find($id);
        if (! $user || ! $user->is_active) {
            $this->tokens->revokeAll(SubjectType::ADMIN, $id);
            throw new HttpException(401, 'Compte désactivé');
        }
        $profile = self::toProfile($user);

        return ['accessToken' => $this->signAccess($profile), 'refreshToken' => $newRaw, 'user' => $profile];
    }

    public function me(int $adminId): array
    {
        $user = AdminUser::find($adminId) ?? throw new HttpException(401, 'Unauthorized');

        return self::toProfile($user);
    }

    public function changePassword(int $adminId, string $current, string $next, RequestMeta $meta): array
    {
        $user = AdminUser::find($adminId);
        if (! $user || ! self::verifyPassword($current, $user->password_hash)) {
            throw new HttpException(400, 'Mot de passe actuel incorrect');
        }
        $user->update(['password_hash' => self::hashPassword($next)]);
        $this->audit->log($adminId, 'auth.password_changed', 'admin_user', $adminId, null, null, $meta);

        return ['success' => true];
    }
}
