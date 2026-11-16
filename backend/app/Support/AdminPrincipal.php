<?php

namespace App\Support;

use App\Enums\RoleCode;

/** Administrateur authentifié (contenu du JWT d'accès). */
final class AdminPrincipal
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $perms
     */
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly array $roles,
        public readonly array $perms,
    ) {}

    public static function fromPayload(array $p): self
    {
        return new self((int) $p['sub'], (string) ($p['email'] ?? ''), array_values($p['roles'] ?? []), array_values($p['perms'] ?? []));
    }

    public function isSuper(): bool
    {
        return in_array(RoleCode::SUPER_ADMIN->value, $this->roles, true);
    }

    public function can(string $permission): bool
    {
        return $this->isSuper() || in_array($permission, $this->perms, true);
    }

    public function hasRole(RoleCode $role): bool
    {
        return in_array($role->value, $this->roles, true);
    }
}
