<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (Gateway)
|--------------------------------------------------------------------------
|
| These routes are prefixed with /v1 automatically by the bootstrap/app.php
| configuration. The gateway handles three types of routes:
|
| 1. Local routes - Handled directly by this gateway (e.g., SMS)
| 2. Proxy routes - Forwarded to the origin API server
| 3. Blocked routes - Return 404 (e.g., admin endpoints)
|
*/

/*
|--------------------------------------------------------------------------
| Local Routes (Handled by Gateway)
|--------------------------------------------------------------------------
*/

// SMS verification code - handled locally via Aliyun SMS
Route::post('auth/send-code', [AuthController::class, 'sendCode'])
    ->middleware(['gateway.auth', 'gateway.ratelimit:sms'])
    ->name('auth.send-code');

/*
|--------------------------------------------------------------------------
| Proxy Routes (Forwarded to Origin API)
|--------------------------------------------------------------------------
|
| All other routes are forwarded to the origin API server.
| The ProxyController handles:
| - Regular API requests
| - File upload requests (with streaming)
| - Static file requests
|
*/

// Upload endpoint with special rate limiting
Route::post('upload/image', [ProxyController::class, 'handle'])
    ->middleware('gateway.ratelimit:upload')
    ->name('upload.image');

// Catch-all route for proxying to origin API
// This must be last so it doesn't override specific routes
Route::match(['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], '{path}', [ProxyController::class, 'handle'])
    ->where('path', '.*')
    ->name('proxy.catchall');
