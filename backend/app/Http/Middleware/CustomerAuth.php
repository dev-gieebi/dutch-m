<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Route client : JWT client obligatoire.
 * Avec le paramètre « optional » (->middleware('customer:optional')), la route reste publique
 * mais exploite le client connecté s'il y en a un.
 */
class CustomerAuth
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        $token = $request->bearerToken();
        $optional = $mode === 'optional';
        if (! $token) {
            if ($optional) {
                return $next($request);
            }
            throw new HttpException(401, 'Authentification requise');
        }
        $payload = $this->tokens->verifyCustomer($token);
        if (! $payload && ! $optional) {
            throw new HttpException(401, 'Session expirée, veuillez vous reconnecter');
        }
        if ($payload) {
            $request->attributes->set('customerId', (int) $payload['sub']);
        }

        return $next($request);
    }
}
