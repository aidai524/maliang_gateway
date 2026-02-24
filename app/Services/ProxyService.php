<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProxyService
{
    protected Client $client;
    protected string $originUrl;
    protected int $timeout;

    public function __construct()
    {
        $this->originUrl = rtrim(config('gateway.origin_url'), '/');
        $this->timeout = config('gateway.timeout', 120);

        $this->client = new Client([
            'base_uri' => $this->originUrl,
            'timeout' => $this->timeout,
            'connect_timeout' => 10,
            'http_errors' => false, // We'll handle errors ourselves
            'allow_redirects' => false,
            'decode_content' => false, // Don't auto-decompress
            'verify' => true,
        ]);
    }

    /**
     * Proxy a request to the origin API
     */
    public function proxy(Request $request, string $path): Response|StreamedResponse
    {
        $method = $request->method();
        $startTime = microtime(true);

        try {
            // Build the request options
            $options = $this->buildRequestOptions($request);

            // Log the request
            $this->logRequest($request, $path);

            // Execute the request
            $response = $this->client->request($method, $path, $options);

            // Calculate duration
            $duration = round((microtime(true) - $startTime) * 1000, 2);

            // Log the response
            $this->logResponse($response, $path, $duration);

            // Build and return the response
            return $this->buildResponse($response);

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            Log::error('Gateway proxy error', [
                'path' => $path,
                'method' => $method,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);

            if ($e->hasResponse()) {
                return $this->buildResponse($e->getResponse());
            }

            return new Response(
                json_encode(['error' => 'PROXY_ERROR', 'message' => 'Failed to connect to origin server']),
                502,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Proxy a file upload request with streaming
     */
    public function proxyUpload(Request $request, string $path): Response
    {
        $startTime = microtime(true);

        try {
            $file = $request->file('file');
            
            if (!$file || !$file->isValid()) {
                return new Response(
                    json_encode(['error' => 'INVALID_FILE', 'message' => 'No valid file uploaded']),
                    400,
                    ['Content-Type' => 'application/json']
                );
            }

            // Build multipart upload
            $multipart = [
                [
                    'name' => 'file',
                    'contents' => fopen($file->getRealPath(), 'r'),
                    'filename' => $file->getClientOriginalName(),
                    'headers' => ['Content-Type' => $file->getMimeType()],
                ],
            ];

            // Add any additional form data
            foreach ($request->except('file') as $key => $value) {
                $multipart[] = [
                    'name' => $key,
                    'contents' => $value,
                ];
            }

            $options = [
                'multipart' => $multipart,
                'headers' => $this->buildForwardHeaders($request, [
                    'Content-Type' => 'multipart/form-data',
                ]),
            ];

            // Log the upload request
            Log::info('Gateway upload request', [
                'path' => $path,
                'file_size' => $file->getSize(),
                'file_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
            ]);

            // Execute the request
            $response = $this->client->request('POST', $path, $options);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Gateway upload response', [
                'path' => $path,
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
            ]);

            return $this->buildResponse($response);

        } catch (RequestException $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('Gateway upload error', [
                'path' => $path,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
            ]);

            if ($e->hasResponse()) {
                return $this->buildResponse($e->getResponse());
            }

            return new Response(
                json_encode(['error' => 'UPLOAD_ERROR', 'message' => 'Failed to upload file to origin server']),
                502,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Build request options for Guzzle
     */
    protected function buildRequestOptions(Request $request): array
    {
        $options = [
            'headers' => $this->buildForwardHeaders($request),
        ];

        // Add query parameters
        if ($request->query->count() > 0) {
            $options['query'] = $request->query->all();
        }

        // Add body for POST/PUT/PATCH requests
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $contentType = $request->header('Content-Type');
            
            if (str_contains($contentType, 'application/json')) {
                $options['body'] = $request->getContent();
            } elseif (str_contains($contentType, 'multipart/form-data')) {
                // Handle multipart form data
                $options['multipart'] = $this->buildMultipartData($request);
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                $options['form_params'] = $request->request->all();
            } else {
                $options['body'] = $request->getContent();
            }
        }

        return $options;
    }

    /**
     * Build headers to forward to origin
     */
    protected function buildForwardHeaders(Request $request, array $extra = []): array
    {
        $headers = [];

        // Forward Authorization header (Bearer token)
        if ($request->hasHeader('Authorization')) {
            $headers['Authorization'] = $request->header('Authorization');
        }

        // Forward Idempotency-Key header
        if ($request->hasHeader('Idempotency-Key')) {
            $headers['Idempotency-Key'] = $request->header('Idempotency-Key');
        }

        // Forward X-Admin-Key header (if needed)
        if ($request->hasHeader('X-Admin-Key')) {
            $headers['X-Admin-Key'] = $request->header('X-Admin-Key');
        }

        // Forward Content-Type for JSON requests
        if ($request->hasHeader('Content-Type')) {
            $contentType = $request->header('Content-Type');
            if (!str_contains($contentType, 'multipart/form-data')) {
                $headers['Content-Type'] = $contentType;
            }
        }

        // Add X-Forwarded headers
        $headers['X-Forwarded-For'] = $request->ip();
        $headers['X-Forwarded-Host'] = $request->getHost();
        $headers['X-Forwarded-Proto'] = $request->getScheme();

        // Add gateway identification
        $headers['X-Gateway-Name'] = config('gateway.name', 'MLGateway');
        $headers['X-Gateway-Time'] = now()->toIso8601String();

        return array_merge($headers, $extra);
    }

    /**
     * Build multipart data from request
     */
    protected function buildMultipartData(Request $request): array
    {
        $multipart = [];

        // Add all post data
        foreach ($request->request->all() as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $multipart[] = ['name' => "{$key}[]", 'contents' => $item];
                }
            } else {
                $multipart[] = ['name' => $key, 'contents' => $value];
            }
        }

        // Add files
        foreach ($request->allFiles() as $key => $file) {
            if (is_array($file)) {
                foreach ($file as $f) {
                    if ($f->isValid()) {
                        $multipart[] = [
                            'name' => "{$key}[]",
                            'contents' => fopen($f->getRealPath(), 'r'),
                            'filename' => $f->getClientOriginalName(),
                        ];
                    }
                }
            } else {
                if ($file->isValid()) {
                    $multipart[] = [
                        'name' => $key,
                        'contents' => fopen($file->getRealPath(), 'r'),
                        'filename' => $file->getClientOriginalName(),
                    ];
                }
            }
        }

        return $multipart;
    }

    /**
     * Build Laravel response from Guzzle response
     */
    protected function buildResponse($guzzleResponse): Response
    {
        $body = (string) $guzzleResponse->getBody();
        
        // Get response headers
        $headers = [];
        foreach ($guzzleResponse->getHeaders() as $name => $values) {
            // Filter out headers that should not be forwarded
            if (!in_array(strtolower($name), ['transfer-encoding', 'connection', 'keep-alive'])) {
                $headers[$name] = $values;
            }
        }

        // Add gateway timing header
        $headers['X-Gateway-Time'] = [now()->toIso8601String()];

        return new Response($body, $guzzleResponse->getStatusCode(), $headers);
    }

    /**
     * Log incoming request
     */
    protected function logRequest(Request $request, string $path): void
    {
        Log::info('Gateway request', [
            'method' => $request->method(),
            'path' => $path,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'has_auth' => $request->hasHeader('Authorization'),
            'query' => $request->query->count() > 0 ? $request->query->all() : null,
        ]);
    }

    /**
     * Log outgoing response
     */
    protected function logResponse($response, string $path, float $duration): void
    {
        Log::info('Gateway response', [
            'path' => $path,
            'status' => $response->getStatusCode(),
            'duration_ms' => $duration,
            'content_length' => $response->getHeaderLine('Content-Length') ?: 'unknown',
        ]);
    }
    /**
     * Proxy static file requests (for uploads)
     */
    public function proxyStatic(Request $request, string $path): Response
    {
        $startTime = microtime(true);

        try {
            $response = $this->client->get($path, [
                'headers' => [
                    'X-Forwarded-For' => $request->ip(),
                ],
            ]);

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('Gateway static file response', [
                'path' => $path,
                'status' => $response->getStatusCode(),
                'duration_ms' => $duration,
            ]);

            return $this->buildResponse($response);

        } catch (RequestException $e) {
            Log::error('Gateway static file error', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            if ($e->hasResponse()) {
                return $this->buildResponse($e->getResponse());
            }

            return new Response(
                'File not found',
                404,
                ['Content-Type' => 'text/plain']
            );
        }
    }

}
