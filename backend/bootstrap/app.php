<?php

use App\Http\Middleware\CheckRole;
use App\Http\Middleware\EnsureHasAccess;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sanctum stateful no es necesario porque usamos token bearer puro
        $middleware->alias([
            'role'       => CheckRole::class,
            'con_acceso' => EnsureHasAccess::class,
        ]);

        // CORS abierto al gateway (mismo origen, pero por las dudas)
        $middleware->api(prepend: [
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (ValidationException $e, Request $request) {
            return response()->json([
                'error'   => 'validation_failed',
                'message' => 'Hay errores de validación en los datos enviados.',
                'errors'  => $e->errors(),
            ], 422);
        });

        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            return response()->json([
                'error'   => 'unauthenticated',
                'message' => 'Debe iniciar sesión.',
            ], 401);
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error'   => 'not_found',
                    'message' => 'Recurso no encontrado.',
                ], 404);
            }
        });

        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'error'   => 'http_error',
                    'message' => $e->getMessage() ?: 'Error en la solicitud.',
                ], $e->getStatusCode());
            }
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $debug = config('app.debug');
                return response()->json([
                    'error'   => 'server_error',
                    'message' => $debug ? $e->getMessage() : 'Error interno del servidor.',
                    'trace'   => $debug ? collect($e->getTrace())->take(5) : null,
                ], 500);
            }
        });
    })
    ->create();
