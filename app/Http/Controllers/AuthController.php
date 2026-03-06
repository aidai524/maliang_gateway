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
     * 
     * This is called by external services (like dream-api.sendto.you) to send SMS.
     */
    public function sendSmsCode(Request $request): JsonResponse
    {
        // Validate request
        $request->validate([
            'phone' => 'required|string|regex:/^1[3-9]\d{9}$/',
            'code' => 'required|string|size:6', // 6-digit verification code from caller
        ]);

        $phone = $request->input('phone');
        $code = $request->input('code');

        // Send verification code via Aliyun SMS
        $result = $this->smsService->sendVerificationCode($phone, $code);

        if ($result['success']) {
            $response = [
                'success' => true,
                'message' => $result['message'],
            ];

            return new JsonResponse($response, 200);
        }

        return new JsonResponse([
            'success' => false,
            'message' => $result['message'],
        ], 400);
    }
}
