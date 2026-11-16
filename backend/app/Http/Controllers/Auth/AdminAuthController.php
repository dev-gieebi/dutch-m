<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SubjectType;
use App\Http\Controllers\Controller;
use App\Services\Auth\AdminAuthService;
use App\Services\TokenService;
use App\Support\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuthController extends Controller
{
    public function __construct(
        private readonly AdminAuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    private function respond(array $result): JsonResponse
    {
        if ($result['step'] !== 'DONE') {
            return response()->json($result);
        }
        $cookie = $this->tokens->makeCookie(SubjectType::ADMIN, $result['refreshToken']);
        unset($result['refreshToken']);

        return response()->json($result)->withCookie($cookie);
    }

    public function login(Request $request): JsonResponse
    {
        $v = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:1', 'max:128'],
        ], ['email.email' => 'Adresse e-mail invalide']);

        return $this->respond($this->auth->login($v['email'], $v['password'], $this->meta($request)));
    }

    public function setup(Request $request): array
    {
        $v = $request->validate(['tempToken' => ['required', 'string']]);

        return $this->auth->setupWithTempToken($v['tempToken']);
    }

    public function verify(Request $request): JsonResponse
    {
        $v = $request->validate([
            'tempToken' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
        ], ['code.size' => 'Le code comporte 6 chiffres']);

        return $this->respond($this->auth->verify2fa($v['tempToken'], $v['code'], $this->meta($request)));
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            $r = $this->auth->refresh($request->cookie(TokenService::ADMIN_RT_COOKIE), $this->meta($request));
        } catch (\Throwable $e) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['statusCode' => 401, 'message' => $e->getMessage() ?: 'Session expirée'], 401)
                    ->withCookie($this->tokens->forgetCookie(SubjectType::ADMIN)),
            );
        }

        return response()->json(['accessToken' => $r['accessToken'], 'user' => $r['user']])
            ->withCookie($this->tokens->makeCookie(SubjectType::ADMIN, $r['refreshToken']));
    }

    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revoke($request->cookie(TokenService::ADMIN_RT_COOKIE));

        return response()->json(['success' => true])->withCookie($this->tokens->forgetCookie(SubjectType::ADMIN));
    }

    public function me(Request $request): array
    {
        return $this->auth->me($this->admin($request)->id);
    }

    public function setupMine(Request $request): array
    {
        return $this->auth->setupForCurrent($this->admin($request)->id);
    }

    public function enableMine(Request $request): array
    {
        $v = $request->validate(['code' => ['required', 'string', 'size:6']], ['code.size' => 'Le code comporte 6 chiffres']);

        return $this->auth->enableForCurrent($this->admin($request)->id, $v['code'], $this->meta($request));
    }

    public function changePassword(Request $request): array
    {
        $v = $request->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'regex:'.Rules::ADMIN_PASSWORD_REGEX],
        ], ['newPassword.regex' => Rules::ADMIN_PASSWORD_MESSAGE]);

        return $this->auth->changePassword($this->admin($request)->id, $v['currentPassword'], $v['newPassword'], $this->meta($request));
    }
}
