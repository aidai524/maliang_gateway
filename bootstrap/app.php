<?php

use App\Http\Middleware\ApiKeyAndIpAuth;
use App\Http\Middleware\GatewayLogger;
use App\Http\Middleware\GatewayRateLimiter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // API routes with /v1 prefix
            Route::prefix('v1')
                ->middleware(['gateway.logger', 'gateway.ratelimit:general'])
                ->group(base_path('routes/api.php'));
            
            // Health check endpoint
            Route::get('/health', [\App\Http\Controllers\ProxyController::class, 'health']);
            
            // Static files proxy (for uploads)
            Route::get('/uploads/{path}', [\App\Http\Controllers\ProxyController::class, 'proxyStatic'])
                ->where('path', '.*');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register gateway middleware aliases
        $middleware->alias([
            'gateway.logger' => GatewayLogger::class,
            'gateway.ratelimit' => GatewayRateLimiter::class,
            'gateway.auth' => ApiKeyAndIpAuth::class,
        ]);
        
        // Trust all proxies (since we're a gateway)
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
