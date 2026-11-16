<?php

namespace App\Http\Middleware;

use App\Services\TokenService;
use App\Support\AdminPrincipal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Route du back-office : JWT administrateur obligatoire + permissions RBAC.
 * Usage : ->middleware('admin') ou ->middleware('admin:catalog.read,exports') (toutes requises).
 */
class AdminAuth
{
    public function __construct(private readonly TokenService $tokens) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $token = $request->bearerToken();
        if (! $token) {
            throw new HttpException(401, 'Authentification requise');
        }
        $payload = $this->tokens->verifyAdmin($token);
        if (! $payload) {
            throw new HttpException(401, 'Session expirée, veuillez vous reconnecter');
        }
        $admin = AdminPrincipal::fromPayload($payload);
        $request->attributes->set('admin', $admin);
        // Utilisé par l'autorisation des canaux de diffusion (routes/channels.php).
        $request->setUserResolver(fn () => $admin);

        if (! $admin->isSuper()) {
            foreach ($permissions as $p) {
                if (! in_array($p, $admin->perms, true)) {
                    throw new HttpException(403, "Vous n'avez pas les permissions nécessaires pour cette action");
                }
            }
        }

        return $next($request);
    }
}
