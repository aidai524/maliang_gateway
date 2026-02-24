# Maliang API Gateway - Deployment Guide

This is a PHP Laravel-based API gateway for proxying requests from a China-based server to the origin API server.

## Architecture

```
┌─────────────┐     ┌─────────────────────┐     ┌──────────────────────┐
│   Client    │────▶│  Laravel Gateway    │────▶│  Origin API          │
│  (China)    │     │  (China Server)     │     │  dream-api.sendto.you│
└─────────────┘     └─────────────────────┘     └──────────────────────┘
```

## Requirements

- PHP 8.2+
- Composer
- Nginx
- PHP-FPM
- Redis (for rate limiting and caching)
- SSL certificate

## Server Setup (Ubuntu)

### 1. Install Dependencies

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install PHP and extensions
sudo apt install -y php8.2 php8.2-fpm php8.2-cli php8.2-mbstring \
    php8.2-xml php8.2-curl php8.2-zip php8.2-intl php8.2-bcmath \
    php8.2-redis php8.2-mysql

# Install Nginx
sudo apt install -y nginx

# Install Redis
sudo apt install -y redis-server
sudo systemctl enable redis-server
```

### 2. Deploy Application

```bash
# Create web directory
sudo mkdir -p /var/www/maliang_gateway

# Clone or copy the application
sudo chown -R $USER:www-data /var/www/maliang_gateway

# Install dependencies
cd /var/www/maliang_gateway
composer install --no-dev --optimize-autoloader

# Set permissions
sudo chown -R www-data:www-data /var/www/maliang_gateway
sudo chmod -R 775 /var/www/maliang_gateway/storage
sudo chmod -R 775 /var/www/maliang_gateway/bootstrap/cache
```

### 3. Configure Environment

```bash
# Copy environment file
cp .env.example .env

# Edit configuration
nano .env
```

**Required Environment Variables:**

```env
# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-gateway-domain.com

# Gateway
ORIGIN_API_URL=https://dream-api.sendto.you/api
ORIGIN_API_TIMEOUT=120

# Rate Limiting
RATE_LIMIT_PER_MINUTE=60
RATE_LIMIT_UPLOAD_PER_MINUTE=10

# Aliyun SMS
ALIYUN_ACCESS_KEY_ID=your_access_key_id
ALIYUN_ACCESS_KEY_SECRET=your_access_key_secret
ALIYUN_SMS_SIGN_NAME=your_sign_name
ALIYUN_SMS_TEMPLATE_CODE=your_template_code

# Redis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Database (if needed for logging)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=maliang_gateway
DB_USERNAME=your_db_user
DB_PASSWORD=your_db_password
```

### 4. Generate Application Key

```bash
php artisan key:generate
```

### 5. Configure Nginx

```bash
# Copy Nginx configuration
sudo cp deploy/nginx.conf /etc/nginx/sites-available/maliang_gateway

# Edit configuration with your domain
sudo nano /etc/nginx/sites-available/maliang_gateway

# Enable site
sudo ln -s /etc/nginx/sites-available/maliang_gateway /etc/nginx/sites-enabled/

# Test configuration
sudo nginx -t

# Reload Nginx
sudo systemctl reload nginx
```

### 6. Configure SSL (Let's Encrypt)

```bash
# Install Certbot
sudo apt install -y certbot python3-certbot-nginx

# Obtain certificate
sudo certbot --nginx -d your-gateway-domain.com

# Auto-renewal
sudo systemctl enable certbot.timer
```

### 7. Configure PHP-FPM

Edit `/etc/php/8.2/fpm/pool.d/www.conf`:

```ini
; Increase these settings for large file uploads
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 50M
php_admin_value[memory_limit] = 256M
php_admin_value[max_execution_time] = 120
```

Restart PHP-FPM:

```bash
sudo systemctl restart php8.2-fpm
```

### 8. Set Up Log Rotation

Create `/etc/logrotate.d/maliang_gateway`:

```
/var/www/maliang_gateway/storage/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    notifempty
    missingok
    create 644 www-data www-data
}
```

## Monitoring

### Health Check

```bash
curl https://your-gateway-domain.com/health
```

Expected response:
```json
{
  "status": "ok",
  "gateway": "MLGateway",
  "timestamp": "2026-02-24T15:00:00+00:00",
  "origin": "https://dream-api.sendto.you/api"
}
```

### Log Monitoring

```bash
# Gateway logs
tail -f /var/www/maliang_gateway/storage/logs/gateway.log

# Nginx access logs
tail -f /var/log/nginx/maliang_gateway_access.log

# Nginx error logs
tail -f /var/log/nginx/maliang_gateway_error.log
```

## API Endpoint Summary

| Endpoint | Method | Handled By | Description |
|----------|--------|------------|-------------|
| `/health` | GET | Gateway | Health check |
| `/v1/auth/send-code` | POST | Gateway (Local) | Send SMS verification code |
| `/v1/upload/image` | POST | Proxy | Upload image (max 50MB) |
| `/v1/admin/*` | * | Blocked | Returns 404 |
| `/v1/*` | * | Proxy | Forwarded to origin |
| `/uploads/*` | GET | Proxy | Static files from origin |

## Rate Limits

| Type | Limit | Window |
|------|-------|--------|
| General API | 60 requests | 1 minute |
| File Upload | 10 requests | 1 minute |
| SMS | 1 request | 1 minute |

## Troubleshooting

### 502 Bad Gateway

1. Check if PHP-FPM is running: `sudo systemctl status php8.2-fpm`
2. Check Nginx error logs: `tail -f /var/log/nginx/maliang_gateway_error.log`

### File Upload Fails

1. Check PHP settings in `/etc/php/8.2/fpm/php.ini`
2. Check Nginx `client_max_body_size` setting

### Rate Limiting Issues

1. Clear Redis cache: `redis-cli FLUSHDB`
2. Check rate limit headers in response

## Updates

```bash
cd /var/www/maliang_gateway

# Pull latest changes
git pull origin main

# Update dependencies
composer install --no-dev --optimize-autoloader

# Clear cache
php artisan optimize:clear

# Cache configuration
php artisan optimize

# Restart PHP-FPM
sudo systemctl restart php8.2-fpm
```

## Security Recommendations

1. **Firewall**: Only allow ports 80, 443, and SSH
2. **Fail2ban**: Install and configure for SSH protection
3. **Updates**: Keep system and packages updated
4. **Monitoring**: Set up log monitoring and alerts
5. **Backups**: Regular database and configuration backups
