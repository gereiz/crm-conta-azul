<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;

date_default_timezone_set(env('APP_TIMEZONE', 'America/Sao_Paulo'));

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
        $middleware->validateCsrfTokens(except: [
            'install',
            'install/*',
        ]);
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            \App\Http\Middleware\CheckInstalled::class,
        ]);

        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->report(function (TokenMismatchException $e) {
            try {
                $request = request();

                Log::warning('CSRF token mismatch', [
                    'method' => $request?->method(),
                    'full_url' => $request?->fullUrl(),
                    'host' => $request?->getHost(),
                    'route' => optional($request?->route())->getName(),
                    'session_id' => session()->getId(),
                    'has_session_token' => ! empty(session()->token()),
                    'has_xsrf_cookie' => $request?->cookies->has('XSRF-TOKEN'),
                    'has_session_cookie' => $request?->cookies->has(config('session.cookie')),
                    'header_x_csrf_token' => $request?->headers->has('X-CSRF-TOKEN'),
                    'header_x_xsrf_token' => $request?->headers->has('X-XSRF-TOKEN'),
                    'user_id' => optional($request?->user())->id,
                ]);
            } catch (\Throwable $ignored) {
                // Evita falha no logger mascarar o 419 original.
            }
        });
    })->create();
