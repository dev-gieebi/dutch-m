<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Limitation de débit propre à chaque route (comme @Throttle de l'ancienne API) :
 * le compteur est calculé par route + adresse IP, et non partagé entre toutes les routes.
 * Usage : ->middleware('limit:10,1') = 10 requêtes par minute.
 */
class RouteThrottle extends ThrottleRequests
{
    protected function resolveRequestSignature($request)
    {
        $route = $request->route();

        return sha1(implode('|', [$request->method(), $route?->uri() ?? $request->path(), $request->ip()]));
    }
}
