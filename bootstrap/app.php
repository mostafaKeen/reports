<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->validateCsrfTokens(except: [
            'webhook/bitrix24',
            'bitrix24/install',
            'bitrix24/install/complete',
            'bitrix24/callback',
            'report/*/clear-cache',
            'crm-chat',
            'crm-chat/analyze',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();