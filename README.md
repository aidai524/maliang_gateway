# Maliang API Gateway

PHP Laravel-based API gateway for proxying requests from China to the origin API server.

## Architecture

```
┌─────────────┐     ┌─────────────────────┐     ┌──────────────────────┐
│   Client    │────▶│  Laravel Gateway    │────▶│  Origin API          │
│  (China)    │     │  (China Server)     │     │  dream-api.sendto.you│
└─────────────┘     └─────────────────────┘     └──────────────────────┘
```

## Features

- **API Proxying**: Forwards most `/v1/*` requests to the origin API
- **Local SMS**: Handles `/v1/auth/send-code` locally via Aliyun SMS
- **File Upload**: Streaming proxy for `/v1/upload/image` (up to 50MB)
- **Rate Limiting**: Configurable per-endpoint rate limits
- **Request Logging**: Detailed logging for debugging and monitoring
- **Admin Blocking**: Returns 404 for all `/v1/admin/*` endpoints

## Route Summary

| Route | Method | Handler |
|-------|--------|---------|
| `/health` | GET | Health check |
| `/v1/auth/send-code` | POST | Local (Aliyun SMS) |
| `/v1/upload/image` | POST | Proxy (streaming upload) |
| `/v1/admin/*` | * | 404 (blocked) |
| `/v1/*` | * | Proxy to origin |
| `/uploads/*` | GET | Static file proxy |

## Quick Start

### 1. Install Dependencies

```bash
composer install
```

### 2. Configure Environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with your configuration:

```env
# Gateway Configuration
ORIGIN_API_URL=https://dream-api.sendto.you/api
ORIGIN_API_TIMEOUT=120

# Aliyun SMS (for /v1/auth/send-code)
ALIYUN_ACCESS_KEY_ID=your_key
ALIYUN_ACCESS_KEY_SECRET=your_secret
ALIYUN_SMS_SIGN_NAME=your_sign
ALIYUN_SMS_TEMPLATE_CODE=your_template

# Rate Limiting
RATE_LIMIT_PER_MINUTE=60
```

### 3. Run Development Server

```bash
php artisan serve
```

## Production Deployment

See [DEPLOY.md](DEPLOY.md) for detailed production deployment instructions.

## API Examples

### Health Check

```bash
curl http://localhost:8000/health
```

### Send SMS Code (Local)

```bash
curl -X POST http://localhost:8000/v1/auth/send-code \
  -H "Content-Type: application/json" \
  -d '{"phone": "13800138000"}'
```

### Upload Image (Proxied)

```bash
curl -X POST http://localhost:8000/v1/upload/image \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@/path/to/image.jpg"
```

### Generate Image (Proxied)

```bash
curl -X POST http://localhost:8000/v1/proxy/images/generate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"prompt": "A cute cat", "mode": "draft"}'
```

## Configuration

### Gateway Config (`config/gateway.php`)

- `origin_url`: Origin API base URL
- `timeout`: Request timeout in seconds
- `rate_limit.per_minute`: General rate limit
- `rate_limit.upload_per_minute`: Upload rate limit

### SMS Config (`config/services.php`)

- `aliyun_sms.*`: Aliyun SMS credentials

## Logging

Gateway logs are written to `storage/logs/gateway.log` with daily rotation (30 days).

View logs:

```bash
tail -f storage/logs/gateway.log
```

## Rate Limit Headers

All responses include rate limit headers:

- `X-RateLimit-Limit`: Maximum requests per minute
- `X-RateLimit-Remaining`: Remaining requests

## License

MIT
