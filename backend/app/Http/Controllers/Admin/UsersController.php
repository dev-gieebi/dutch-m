<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RoleCode;
use App\Http\Controllers\Controller;
use App\Services\UsersService;
use App\Support\Rules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UsersController extends Controller
{
    public function __construct(private readonly UsersService $users) {}

    public function drivers(): array
    {
        return $this->users->drivers();
    }

    public function mySessions(Request $request): array
    {
        $a = $this->admin($request);

        return $this->users->sessions($a, $a->id);
    }

    public function list(): array
    {
        return $this->users->list();
    }

    public function create(Request $request): array
    {
        $v = $request->validate([
            'email' => ['required', 'email'],
            'fullName' => ['required', 'string', 'between:2,120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'regex:'.Rules::ADMIN_PASSWORD_REGEX],
            'roleCodes' => ['required', 'array', 'min:1'],
            'roleCodes.*' => [Rule::enum(RoleCode::class)],
        ], ['email.email' => 'Adresse e-mail invalide', 'password.regex' => Rules::ADMIN_PASSWORD_MESSAGE, 'roleCodes.min' => 'Au moins un rôle', 'roleCodes.required' => 'Au moins un rôle']);

        return $this->users->create($this->admin($request), $v, $this->meta($request));
    }

    public function update(Request $request, int $id): array
    {
        $v = $request->validate([
            'fullName' => ['sometimes', 'string', 'between:2,120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'isActive' => ['sometimes', 'boolean'],
            'roleCodes' => ['sometimes', 'array'],
            'roleCodes.*' => [Rule::enum(RoleCode::class)],
        ]);

        return $this->users->update($this->admin($request), $id, $v, $this->meta($request));
    }

    public function resetPassword(Request $request, int $id): array
    {
        $v = $request->validate(['password' => ['required', 'string', 'regex:'.Rules::ADMIN_PASSWORD_REGEX]], ['password.regex' => Rules::ADMIN_PASSWORD_MESSAGE]);

        return $this->users->resetPassword($this->admin($request), $id, $v['password'], $this->meta($request));
    }

    public function reset2fa(Request $request, int $id): array
    {
        return $this->users->reset2fa($this->admin($request), $id, $this->meta($request));
    }

    public function unlock(Request $request, int $id): array
    {
        return $this->users->unlock($this->admin($request), $id, $this->meta($request));
    }

    public function remove(Request $request, int $id): array
    {
        return $this->users->remove($this->admin($request), $id, $this->meta($request));
    }

    public function sessions(Request $request, int $id): array
    {
        return $this->users->sessions($this->admin($request), $id);
    }

    public function revokeSession(Request $request, int $id, int $tokenId): array
    {
        return $this->users->revokeSession($this->admin($request), $id, $tokenId, $this->meta($request));
    }

    public function roles(): array
    {
        return $this->users->listRoles();
    }

    public function permissions(): array
    {
        return $this->users->listPermissions();
    }
}
