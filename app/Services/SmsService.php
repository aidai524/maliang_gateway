<?php

namespace App\Services;

use AlibabaCloud\SDK\Dysmsapi\V20170525\Dysmsapi;
use AlibabaCloud\SDK\Dysmsapi\V20170525\Models\SendSmsRequest;
use AlibabaCloud\Tea\Utils\Utils\RuntimeOptions;
use Darabonba\OpenApi\Models\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class SmsService
{
    protected ?Dysmsapi $client = null;
    protected string $accessKeyId;
    protected string $accessKeySecret;
    protected string $signName;
    protected string $templateCode;
    protected string $regionId;

    public function __construct()
    {
        $this->accessKeyId = config('services.aliyun_sms.access_key_id', env('ALIYUN_ACCESS_KEY_ID'));
        $this->accessKeySecret = config('services.aliyun_sms.access_key_secret', env('ALIYUN_ACCESS_KEY_SECRET'));
        $this->signName = config('services.aliyun_sms.sign_name', env('ALIYUN_SMS_SIGN_NAME'));
        $this->templateCode = config('services.aliyun_sms.template_code', env('ALIYUN_SMS_TEMPLATE_CODE'));
        $this->regionId = config('services.aliyun_sms.region_id', env('ALIYUN_SMS_REGION_ID', 'cn-hangzhou'));

        if ($this->accessKeyId && $this->accessKeySecret) {
            $this->client = $this->createClient();
        }
    }

    /**
     * Create Aliyun SMS client
     */
    protected function createClient(): Dysmsapi
    {
        $config = new Config([
            'accessKeyId' => $this->accessKeyId,
            'accessKeySecret' => $this->accessKeySecret,
        ]);
        
        $config->endpoint = "dysmsapi.aliyuncs.com";
        
        return new Dysmsapi($config);
    }

    /**
     * Send verification code via SMS
     */
    public function sendVerificationCode(string $phone): array
    {
        // Validate phone number format (Chinese mobile)
        if (!preg_match('/^1[3-9]\d{9}$/', $phone)) {
            return [
                'success' => false,
                'message' => 'Invalid phone number format',
            ];
        }

        // Check rate limit (max 1 SMS per minute per phone)
        $rateLimitKey = "sms_rate_limit:{$phone}";
        if (Cache::has($rateLimitKey)) {
            return [
                'success' => false,
                'message' => 'Please wait before requesting another code',
            ];
        }

        // Generate 6-digit code
        $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        // Store code in cache (5 minutes expiry)
        $codeKey = "sms_code:{$phone}";
        Cache::put($codeKey, $code, now()->addMinutes(5));

        // Set rate limit
        Cache::put($rateLimitKey, true, now()->addMinute());

        // If Aliyun client is not configured, return code directly (for development)
        if (!$this->client) {
            Log::warning('Aliyun SMS client not configured, returning code directly', [
                'phone' => $phone,
                'code' => $code,
            ]);

            return [
                'success' => true,
                'message' => 'Verification code sent (development mode)',
                'code' => $code, // Only in development
            ];
        }

        try {
            $sendSmsRequest = new SendSmsRequest([
                'phoneNumbers' => $phone,
                'signName' => $this->signName,
                'templateCode' => $this->templateCode,
                'templateParam' => json_encode(['code' => $code]),
            ]);

            $runtimeOptions = new RuntimeOptions([]);
            
            $response = $this->client->sendSmsWithOptions($sendSmsRequest, $runtimeOptions);

            Log::info('SMS sent successfully', [
                'phone' => $phone,
                'request_id' => $response->body->requestId ?? null,
                'biz_id' => $response->body->bizId ?? null,
            ]);

            if ($response->body->code === 'OK') {
                return [
                    'success' => true,
                    'message' => 'Verification code sent successfully',
                ];
            }

            Log::error('SMS send failed', [
                'phone' => $phone,
                'code' => $response->body->code ?? null,
                'message' => $response->body->message ?? null,
            ]);

            return [
                'success' => false,
                'message' => $response->body->message ?? 'Failed to send SMS',
            ];

        } catch (Exception $e) {
            Log::error('SMS send exception', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to send verification code',
            ];
        }
    }

    /**
     * Verify the SMS code
     */
    public function verifyCode(string $phone, string $code): bool
    {
        $codeKey = "sms_code:{$phone}";
        $storedCode = Cache::get($codeKey);

        if (!$storedCode) {
            return false;
        }

        if ($storedCode !== $code) {
            return false;
        }

        // Remove code after successful verification (one-time use)
        Cache::forget($codeKey);

        return true;
    }

    /**
     * Check if code exists and is valid (without consuming it)
     */
    public function hasValidCode(string $phone): bool
    {
        $codeKey = "sms_code:{$phone}";
        return Cache::has($codeKey);
    }
}
