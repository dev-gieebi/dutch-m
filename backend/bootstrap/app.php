<?php

use App\Http\Middleware\AdminAuth;
use App\Http\Middleware\CustomerAuth;
use App\Http\Middleware\RouteThrottle;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api/v1', 'middleware' => ['api', 'admin']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'admin' => AdminAuth::class,
            'customer' => CustomerAuth::class,
            'limit' => RouteThrottle::class,
        ]);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Format unique des erreurs de l'API : { statusCode, message, errors? }
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }
            $status = 500;
            $message = 'Une erreur inattendue est survenue. Veuillez réessayer.';
            $errors = null;

            if ($e instanceof ValidationException) {
                $status = 400;
                $errors = $e->errors();
                $message = array_values($errors)[0][0] ?? 'Données invalides';
            } elseif ($e instanceof ThrottleRequestsException) {
                $status = 429;
                $message = 'Trop de tentatives. Veuillez patienter avant de réessayer.';
            } elseif ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
                $status = 404;
                $message = $e instanceof NotFoundHttpException && $e->getMessage() && ! str_contains($e->getMessage(), 'could not be found')
                    ? $e->getMessage() : 'Ressource introuvable';
            } elseif ($e instanceof AuthenticationException) {
                $status = 401;
                $message = 'Authentification requise';
            } elseif ($e instanceof HttpExceptionInterface) {
                $status = $e->getStatusCode();
                $message = $e->getMessage() ?: $message;
            } elseif ($e instanceof QueryException) {
                $code = $e->errorInfo[1] ?? null;
                if ($code === 1062) {
                    $status = 409;
                    $message = 'Cette valeur existe déjà (doublon).';
                } elseif ($code === 1451) {
                    $status = 409;
                    $message = 'Impossible de supprimer : cet élément est utilisé ailleurs.';
                } else {
                    Log::error($e);
                }
            } else {
                Log::error($e);
                if (config('app.debug')) {
                    $message = $e->getMessage();
                }
            }

            $body = ['statusCode' => $status, 'message' => $message];
            if ($errors) {
                $body['errors'] = $errors;
            }

            return response()->json($body, $status);
        });
    })->create();
