<?php

namespace App\Http\Controllers\Auth;

use App\Enums\SubjectType;
use App\Http\Controllers\Controller;
use App\Services\Auth\CustomerAuthService;
use App\Services\TokenService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerAuthController extends Controller
{
    public function __construct(
        private readonly CustomerAuthService $auth,
        private readonly TokenService $tokens,
    ) {}

    private function respond(array $r, int $status = 200): JsonResponse
    {
        return response()->json(['accessToken' => $r['accessToken'], 'customer' => $r['customer']], $status)
            ->withCookie($this->tokens->makeCookie(SubjectType::CUSTOMER, $r['refreshToken']));
    }

    public function register(Request $request): JsonResponse
    {
        $v = $request->validate([
            'fullName' => ['required', 'string', 'between:2,120'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email'],
            'password' => ['required', 'string', 'between:8,128'],
            'newsletterConsent' => ['nullable', 'boolean'],
        ], ['email.email' => 'Adresse e-mail invalide', 'password.between' => 'Le mot de passe doit contenir au moins 8 caractères']);

        return $this->respond($this->auth->register($v, $this->meta($request)), 201);
    }

    public function login(Request $request): JsonResponse
    {
        $v = $request->validate([
            'identifier' => ['required', 'string', 'between:3,190'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        return $this->respond($this->auth->login($v['identifier'], $v['password'], $this->meta($request)));
    }

    public function refresh(Request $request): JsonResponse
    {
        try {
            return $this->respond($this->auth->refresh($request->cookie(TokenService::CUSTOMER_RT_COOKIE), $this->meta($request)));
        } catch (\Throwable $e) {
            throw new HttpResponseException(
                response()->json(['statusCode' => 401, 'message' => $e->getMessage() ?: 'Session expirée'], 401)
                    ->withCookie($this->tokens->forgetCookie(SubjectType::CUSTOMER)),
            );
        }
    }

    public function logout(Request $request): JsonResponse
    {
        $this->tokens->revoke($request->cookie(TokenService::CUSTOMER_RT_COOKIE));

        return response()->json(['success' => true])->withCookie($this->tokens->forgetCookie(SubjectType::CUSTOMER));
    }

    public function forgot(Request $request): array
    {
        $v = $request->validate(['identifier' => ['required', 'string', 'between:3,190']]);
        $this->auth->forgotPassword($v['identifier']);

        return ['message' => 'Si un compte correspond, un lien de réinitialisation vient de vous être envoyé.'];
    }

    public function reset(Request $request): array
    {
        $v = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'between:8,128'],
        ], ['password.between' => 'Le mot de passe doit contenir au moins 8 caractères']);
        $this->auth->resetPassword($v['token'], $v['password']);

        return ['message' => 'Votre mot de passe a été modifié. Vous pouvez vous connecter.'];
    }

    public function requestVerification(Request $request): array
    {
        $this->auth->requestPhoneVerification($this->customerId($request));

        return ['message' => 'Un code vient de vous être envoyé par SMS.'];
    }

    public function confirmVerification(Request $request): array
    {
        $v = $request->validate(['code' => ['required', 'string', 'size:6']]);

        return $this->auth->confirmPhoneVerification($this->customerId($request), $v['code']);
    }
}
