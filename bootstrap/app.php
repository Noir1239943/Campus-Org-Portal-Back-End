<?php

use App\Http\Middleware\EnsureAdviser;
use App\Http\Middleware\EnsureOfficer;
use App\Http\Middleware\EnsureStudent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render (and most PaaS hosts) sit behind a load balancer.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'student' => EnsureStudent::class,
            'officer' => EnsureOfficer::class,
            'adviser' => EnsureAdviser::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The frontend always expects JSON from /api routes.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson()
        );
    })->create();
