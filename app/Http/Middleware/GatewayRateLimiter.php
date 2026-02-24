<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class GatewayRateLimiter
{
    protected RateLimiter $limiter;

    public function __construct(RateLimiter $limiter)
    {
        $this->limiter = $limiter;
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $type = 'general'): mixed
    {
        // Get the rate limit key based on type
        $key = $this->resolveRequestSignature($request, $type);

        // Get max attempts based on type
        $maxAttempts = $this->getMaxAttempts($type);

        // Check if limit exceeded
        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            return $this->buildResponse($key, $maxAttempts);
        }

        // Increment the counter
        $this->limiter->hit($key, $this->getDecayMinutes($type));

        $response = $next($request);

        // Add rate limit headers
        $response->headers->set('X-RateLimit-Limit', $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', $maxAttempts - $this->limiter->attempts($key) + 1);

        return $response;
    }

    /**
     * Resolve request signature for rate limiting
     */
    protected function resolveRequestSignature(Request $request, string $type): string
    {
        // Use user ID if authenticated, otherwise use IP
        $identifier = $request->ip();

        // If Authorization header exists, use a hash of it for user-based limiting
        if ($request->hasHeader('Authorization')) {
            $identifier = sha1($request->header('Authorization'));
        }

        return "gateway:{$type}:{$identifier}";
    }

    /**
     * Get max attempts based on limit type
     */
    protected function getMaxAttempts(string $type): int
    {
        return match ($type) {
            'upload' => config('gateway.rate_limit.upload_per_minute', 10),
            'sms' => 1, // Only 1 SMS per minute
            default => config('gateway.rate_limit.per_minute', 60),
        };
    }

    /**
     * Get decay time in minutes
     */
    protected function getDecayMinutes(string $type): int
    {
        return match ($type) {
            'sms' => 1, // 1 minute decay for SMS
            default => 1, // 1 minute decay for general
        };
    }

    /**
     * Build rate limit exceeded response
     */
    protected function buildResponse(string $key, int $maxAttempts): JsonResponse
    {
        $retryAfter = $this->limiter->availableIn($key);

        return new JsonResponse([
            'error' => 'RATE_LIMIT_EXCEEDED',
            'message' => 'Too many requests. Please try again later.',
            'retry_after' => $retryAfter,
        ], 429, [
            'Retry-After' => $retryAfter,
            'X-RateLimit-Limit' => $maxAttempts,
            'X-RateLimit-Remaining' => 0,
        ]);
    }
}
