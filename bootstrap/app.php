<?php

use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\EnsureRoleSelected;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating proxy the request arrives on plain HTTP, so
        // without this Laravel builds every asset URL as http:// while the page
        // itself was served over https:// -- the browser blocks the lot as mixed
        // content and renders a blank screen. Trusting the proxy lets
        // X-Forwarded-Proto answer the question instead of the wire.
        //
        // '*' is the right scope rather than a lazy one: nothing reaches the
        // container except through the platform's proxy on a private network,
        // so there is no untrusted party in a position to forge the header.
        $middleware->trustProxies(at: '*');

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'role.selected' => EnsureRoleSelected::class,
            'check.permission' => CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidSignatureException $e) {
            return Inertia::render('link-expired')->toResponse(request())->setStatusCode(403);
        });
    })->create();
