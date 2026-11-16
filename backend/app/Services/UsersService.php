<?php

namespace App\Services;

use App\Enums\RoleCode;
use App\Enums\SubjectType;
use App\Models\AdminUser;
use App\Services\Auth\AdminAuthService;
use App\Support\AdminPrincipal;
use App\Support\Domain;
use App\Support\Format;
use App\Support\RequestMeta;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UsersService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TokenService $tokens,
    ) {}

    public function serialize(AdminUser $u): array
    {
        $defs = Domain::roleDefinitions();

        return [
            'id' => $u->id,
            'email' => $u->email,
            'fullName' => $u->full_name,
            'phone' => $u->phone,
            'isActive' => $u->is_active,
            'twoFactorEnabled' => $u->two_factor_enabled,
            'lastLoginAt' => Format::iso($u->last_login_at),
            'lockedUntil' => Format::iso($u->locked_until),
            'createdAt' => Format::iso($u->created_at),
            'roles' => array_map(fn ($code) => ['code' => $code, 'name' => $defs[$code]['name'] ?? $code], $u->role_codes ?? []),
        ];
    }

    public function list(): array
    {
        return AdminUser::orderBy('full_name')->get()->map(fn ($u) => $this->serialize($u))->all();
    }

    public function drivers(): array
    {
        return AdminUser::where('is_active', true)->orderBy('full_name')->get()
            ->filter(fn ($u) => in_array(RoleCode::LIVREUR->value, $u->role_codes ?? [], true))
            ->map(fn ($u) => ['id' => $u->id, 'fullName' => $u->full_name, 'phone' => $u->phone])
            ->values()->all();
    }

    private function resolveRoles(AdminPrincipal $actor, array $roleCodes): array
    {
        $codes = array_values(array_unique($roleCodes));
        foreach ($codes as $c) {
            if (! RoleCode::tryFrom($c)) {
                throw new HttpException(400, 'Rôle inconnu');
            }
        }
        if (! $actor->isSuper() && in_array(RoleCode::SUPER_ADMIN->value, $codes, true)) {
            throw new HttpException(403, 'Seul un super-administrateur peut attribuer ce rôle');
        }

        return $codes;
    }

    private function findTarget(AdminPrincipal $actor, int $id): AdminUser
    {
        $user = AdminUser::find($id) ?? throw new HttpException(404, 'Utilisateur introuvable');
        if (! $actor->isSuper() && in_array(RoleCode::SUPER_ADMIN->value, $user->role_codes ?? [], true)) {
            throw new HttpException(403, 'Vous ne pouvez pas modifier un super-administrateur');
        }

        return $user;
    }

    public function create(AdminPrincipal $actor, array $dto, RequestMeta $meta): array
    {
        $email = strtolower(trim($dto['email']));
        if (AdminUser::withTrashed()->where('email', $email)->exists()) {
            throw new HttpException(409, 'Cet e-mail est déjà utilisé');
        }
        $user = AdminUser::create([
            'email' => $email,
            'full_name' => $dto['fullName'],
            'phone' => $dto['phone'] ?? null,
            'password_hash' => AdminAuthService::hashPassword($dto['password']),
            'role_codes' => $this->resolveRoles($actor, $dto['roleCodes']),
        ])->refresh();
        $this->audit->log($actor->id, 'user.create', 'admin_user', $user->id, null, $this->serialize($user), $meta);

        return $this->serialize($user);
    }

    public function update(AdminPrincipal $actor, int $id, array $dto, RequestMeta $meta): array
    {
        $user = $this->findTarget($actor, $id);
        $before = $this->serialize($user);
        if (array_key_exists('fullName', $dto)) {
            $user->full_name = $dto['fullName'];
        }
        if (array_key_exists('phone', $dto)) {
            $user->phone = $dto['phone'] ?: null;
        }
        if (array_key_exists('isActive', $dto)) {
            if ($id === $actor->id && ! $dto['isActive']) {
                throw new HttpException(400, 'Vous ne pouvez pas désactiver votre propre compte');
            }
            $user->is_active = $dto['isActive'];
        }
        if (! empty($dto['roleCodes'])) {
            $user->role_codes = $this->resolveRoles($actor, $dto['roleCodes']);
        }
        $user->save();
        if ((array_key_exists('isActive', $dto) && $dto['isActive'] === false) || ! empty($dto['roleCodes'])) {
            $this->tokens->revokeAll(SubjectType::ADMIN, $id);
        }
        $this->audit->log($actor->id, 'user.update', 'admin_user', $id, $before, $this->serialize($user), $meta);

        return $this->serialize($user);
    }

    public function resetPassword(AdminPrincipal $actor, int $id, string $password, RequestMeta $meta): array
    {
        $this->findTarget($actor, $id)->update(['password_hash' => AdminAuthService::hashPassword($password), 'failed_attempts' => 0, 'locked_until' => null]);
        $this->tokens->revokeAll(SubjectType::ADMIN, $id);
        $this->audit->log($actor->id, 'user.reset_password', 'admin_user', $id, null, null, $meta);

        return ['success' => true];
    }

    public function reset2fa(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        $this->findTarget($actor, $id)->update(['two_factor_enabled' => false, 'two_factor_secret' => null]);
        $this->tokens->revokeAll(SubjectType::ADMIN, $id);
        $this->audit->log($actor->id, 'user.reset_2fa', 'admin_user', $id, null, null, $meta);

        return ['success' => true];
    }

    public function unlock(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        $this->findTarget($actor, $id)->update(['failed_attempts' => 0, 'locked_until' => null]);
        $this->audit->log($actor->id, 'user.unlock', 'admin_user', $id, null, null, $meta);

        return ['success' => true];
    }

    public function remove(AdminPrincipal $actor, int $id, RequestMeta $meta): array
    {
        if ($id === $actor->id) {
            throw new HttpException(400, 'Vous ne pouvez pas supprimer votre propre compte');
        }
        $user = $this->findTarget($actor, $id);
        $before = $this->serialize($user);
        $user->update(['is_active' => false]);
        $user->delete();
        $this->tokens->revokeAll(SubjectType::ADMIN, $id);
        $this->audit->log($actor->id, 'user.delete', 'admin_user', $id, $before, null, $meta);

        return ['success' => true];
    }

    public function sessions(AdminPrincipal $actor, int $id): array
    {
        if ($id !== $actor->id) {
            $this->findTarget($actor, $id);
        }

        return $this->tokens->listActive(SubjectType::ADMIN, $id);
    }

    public function revokeSession(AdminPrincipal $actor, int $userId, int $tokenId, RequestMeta $meta): array
    {
        if ($userId !== $actor->id) {
            $this->findTarget($actor, $userId);
        }
        $this->tokens->revokeById($tokenId);
        $this->audit->log($actor->id, 'user.revoke_session', 'admin_user', $userId, null, ['tokenId' => $tokenId], $meta);

        return ['success' => true];
    }

    /** Rôles et permissions : définis dans le code, lecture seule. */
    public function listRoles(): array
    {
        $out = [];
        foreach (Domain::roleDefinitions() as $code => $def) {
            $out[] = [
                'code' => $code,
                'name' => $def['name'],
                'description' => $def['description'],
                'permissions' => array_map(fn ($p) => ['code' => $p, 'name' => Domain::PERMISSION_LABELS[$p]], $def['permissions']),
            ];
        }

        return $out;
    }

    public function listPermissions(): array
    {
        return array_map(fn ($p) => ['code' => $p, 'name' => Domain::PERMISSION_LABELS[$p]], \App\Enums\Permission::values());
    }
}
