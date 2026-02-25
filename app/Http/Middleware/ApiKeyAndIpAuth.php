<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyAndIpAuth
{
    protected array $protectedRoutes = [
        'v1/auth/send-code',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $path = $request->path();

        if (!$this->isProtectedRoute($path)) {
            return $next($request);
        }

        if (!$this->validateApiKey($request)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'INVALID_API_KEY',
                'message' => 'Invalid or missing API key',
            ], 401);
        }

        if (!$this->validateIpAddress($request)) {
            return new JsonResponse([
                'success' => false,
                'error' => 'IP_NOT_ALLOWED',
                'message' => 'IP address not in whitelist',
            ], 403);
        }

        return $next($request);
    }

    protected function isProtectedRoute(string $path): bool
    {
        foreach ($this->protectedRoutes as $route) {
            if ($path === $route || str_starts_with($path, $route . '/')) {
                return true;
            }
        }
        return false;
    }

    protected function validateApiKey(Request $request): bool
    {
        $validApiKeys = $this->getValidApiKeys();

        if (empty($validApiKeys)) {
            return true;
        }

        $apiKey = $request->header('X-API-Key') ?? $request->header('Authorization');

        if ($apiKey === null) {
            return false;
        }

        if (str_starts_with($apiKey, 'Bearer ')) {
            $apiKey = substr($apiKey, 7);
        }

        return in_array($apiKey, $validApiKeys, true);
    }

    protected function validateIpAddress(Request $request): bool
    {
        $whitelist = $this->getIpWhitelist();

        if (empty($whitelist)) {
            return true;
        }

        $clientIp = $this->getClientIp($request);

        foreach ($whitelist as $allowed) {
            if ($this->ipMatches($clientIp, $allowed)) {
                return true;
            }
        }

        return false;
    }

    protected function getClientIp(Request $request): string
    {
        $headers = [
            'X-Forwarded-For',
            'X-Real-IP',
            'CF-Connecting-IP',
            'True-Client-IP',
        ];

        foreach ($headers as $header) {
            $ip = $request->header($header);
            if ($ip) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $request->ip() ?? '127.0.0.1';
    }

    protected function ipMatches(string $clientIp, string $allowed): bool
    {
        if ($clientIp === $allowed) {
            return true;
        }

        if (str_contains($allowed, '/')) {
            return $this->ipInCidr($clientIp, $allowed);
        }

        if (str_contains($allowed, '*')) {
            $pattern = str_replace(['*', '.'], ['\d+', '\.'], $allowed);
            return (bool) preg_match('/^' . $pattern . '$/', $clientIp);
        }

        return false;
    }

    protected function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }

    protected function getValidApiKeys(): array
    {
        $keys = config('gateway.auth.api_keys', env('GATEWAY_API_KEYS', ''));
        
        if (is_array($keys)) {
            return $keys;
        }

        if (empty($keys)) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $keys)));
    }

    protected function getIpWhitelist(): array
    {
        $whitelist = config('gateway.auth.ip_whitelist', env('GATEWAY_IP_WHITELIST', ''));
        
        if (is_array($whitelist)) {
            return $whitelist;
        }

        if (empty($whitelist)) {
            return [];
        }

        return array_filter(array_map('trim', explode(',', $whitelist)));
    }
}
