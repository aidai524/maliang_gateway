<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class GatewayLogger
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);
        $requestId = $this->generateRequestId();

        // Add request ID to request for correlation
        $request->attributes->set('request_id', $requestId);

        // Log incoming request
        $this->logRequest($request, $requestId);

        // Process request
        $response = $next($request);

        // Calculate duration
        $duration = round((microtime(true) - $startTime) * 1000, 2);

        // Log response
        $this->logResponse($request, $response, $requestId, $duration);

        // Add request ID to response headers
        $response->headers->set('X-Request-ID', $requestId);
        $response->headers->set('X-Response-Time', "{$duration}ms");

        return $response;
    }

    /**
     * Generate unique request ID
     */
    protected function generateRequestId(): string
    {
        return 'gw_' . time() . '_' . bin2hex(random_bytes(8));
    }

    /**
     * Log incoming request details
     */
    protected function logRequest(Request $request, string $requestId): void
    {
        $logData = [
            'request_id' => $requestId,
            'type' => 'request',
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query->all(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'has_auth' => $request->hasHeader('Authorization'),
            'content_type' => $request->header('Content-Type'),
            'content_length' => strlen($request->getContent()),
        ];

        // Log file upload details if applicable
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $logData['file'] = [
                'name' => $file->getClientOriginalName(),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        Log::channel('gateway')->info('Gateway Request', $logData);
    }

    /**
     * Log response details
     */
    protected function logResponse(Request $request, Response $response, string $requestId, float $duration): void
    {
        $logData = [
            'request_id' => $requestId,
            'type' => 'response',
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'content_length' => $response->headers->get('Content-Length') ?: strlen($response->getContent()),
            'content_type' => $response->headers->get('Content-Type'),
        ];

        // Determine log level based on status code
        $level = 'info';
        if ($response->getStatusCode() >= 500) {
            $level = 'error';
        } elseif ($response->getStatusCode() >= 400) {
            $level = 'warning';
        }

        Log::channel('gateway')->{$level}('Gateway Response', $logData);
    }
}
