<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // NOTE: prepend() place chaque middleware "devant" les précédents.
        // Pour que le preflight OPTIONS soit traité en premier, on le prepend en dernier.
        $middleware->prepend(HandleCors::class);
        $middleware->prepend(\App\Http\Middleware\LogApiRequest::class);
        $middleware->prepend(\App\Http\Middleware\HandlePreflight::class);
        $middleware->alias([
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
