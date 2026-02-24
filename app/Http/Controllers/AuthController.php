<?php

namespace App\Http\Controllers;

use App\Services\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    protected SmsService $smsService;

    public function __construct(SmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Send SMS verification code (handled locally)
     * 
     * This endpoint is handled by the gateway itself rather than proxied to origin
     * because SMS services work better from a China-based server.
     */
    public function sendCode(Request $request): JsonResponse
    {
        // Validate request
        $request->validate([
            'phone' => 'required|string|regex:/^1[3-9]\d{9}$/',
        ]);

        $phone = $request->input('phone');

        // Send verification code via Aliyun SMS
        $result = $this->smsService->sendVerificationCode($phone);

        if ($result['success']) {
            return new JsonResponse([
                'success' => true,
                'message' => $result['message'],
                // Only include code in development mode
                ...($result['code'] ?? [] ? ['code' => $result['code']] : []),
            ], 200);
        }

        return new JsonResponse([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }
}
