<?php

namespace App\Http\Controllers;

use App\Services\ProxyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ProxyController extends Controller
{
    protected ProxyService $proxyService;

    // Routes that should be handled locally (not proxied)
    protected array $localRoutes = [
        'auth/send-code',
    ];

    // Routes that should return 404 (blocked)
    protected array $blockedRoutes = [
        'admin',
        'admin/',
    ];

    // Routes that handle file uploads
    protected array $uploadRoutes = [
        'upload/image',
    ];

    public function __construct(ProxyService $proxyService)
    {
        $this->proxyService = $proxyService;
    }

    /**
     * Handle all incoming API requests
     */
    public function handle(Request $request, string $path = ''): Response|JsonResponse
    {
        // Normalize the path
        $path = trim($path, '/');

        // Check if route is blocked
        if ($this->isBlockedRoute($path)) {
            return new JsonResponse([
                'error' => 'NOT_FOUND',
                'message' => 'This endpoint is not available through the gateway',
            ], 404);
        }

        // Check if route should be handled locally
        if ($this->isLocalRoute($path)) {
            // Local routes should be handled by their own controllers
            // This should not happen if routes are configured correctly
            return new JsonResponse([
                'error' => 'MISCONFIGURED',
                'message' => 'Local route not properly configured',
            ], 500);
        }

        // Check if this is an upload route
        if ($this->isUploadRoute($path)) {
            return $this->handleUpload($request, $path);
        }

        // Proxy the request to origin
        return $this->proxyService->proxy($request, "v1/{$path}");
    }

    /**
     * Handle file upload requests
     */
    protected function handleUpload(Request $request, string $path): Response|JsonResponse
    {
        // Validate file exists
        if (!$request->hasFile('file')) {
            return new JsonResponse([
                'error' => 'NO_FILE',
                'message' => 'No file uploaded',
            ], 400);
        }

        $file = $request->file('file');

        // Validate file
        if (!$file->isValid()) {
            return new JsonResponse([
                'error' => 'INVALID_FILE',
                'message' => 'Uploaded file is invalid',
            ], 400);
        }

        // Check file size (max 50MB)
        $maxSize = 50 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            return new JsonResponse([
                'error' => 'FILE_TOO_LARGE',
                'message' => 'File size exceeds maximum allowed (50MB)',
            ], 413);
        }

        // Check file type (images only)
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            return new JsonResponse([
                'error' => 'INVALID_FILE_TYPE',
                'message' => 'Only image files are allowed (jpg, png, gif, webp)',
            ], 400);
        }

        return $this->proxyService->proxyUpload($request, "v1/{$path}");
    }

    /**
     * Handle health check endpoint
     */
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'gateway' => config('gateway.name', 'MLGateway'),
            'timestamp' => now()->toIso8601String(),
            'origin' => config('gateway.origin_url'),
        ]);
    }

    /**
     * Handle static file requests (uploads)
     * Proxy static file requests to origin server
     */
    public function proxyStatic(Request $request, string $path): Response
    {
        return $this->proxyService->proxyStatic($request, "uploads/{$path}");
    }


    /**
     * Check if the route should be blocked
     */
    protected function isBlockedRoute(string $path): bool
    {
        foreach ($this->blockedRoutes as $blockedRoute) {
            // Support wildcard matching
            if (Str::endsWith($blockedRoute, '/*')) {
                $prefix = rtrim($blockedRoute, '/*');
                if (Str::startsWith($path, $prefix . '/')) {
                    return true;
                }
            }
            
            if ($path === $blockedRoute || Str::startsWith($path, $blockedRoute . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the route should be handled locally
     */
    protected function isLocalRoute(string $path): bool
    {
        return in_array($path, $this->localRoutes);
    }

    /**
     * Check if the route is an upload route
     */
    protected function isUploadRoute(string $path): bool
    {
        return in_array($path, $this->uploadRoutes);
    }
}
