<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Nothing of our own to add now that the password gate is gone — but this
        // callback still has to be made, because it is what registers the framework's
        // default groups. Left out, "web" is not a group and every page 500s.
        //
        // The one thing added: Paystack POSTs to us with no session and no CSRF
        // token, and cannot be given one. That path is answered only when the HMAC
        // signature over its raw body checks out — see WebhookController.
        $middleware->validateCsrfTokens(except: [
            'webhooks/paystack',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
