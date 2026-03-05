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

## 宝塔面板部署注意事项

如果使用宝塔面板管理的服务器,需要注意以下差异:

### 服务管理命令差异

宝塔面板管理的服务使用 init.d 脚本,而非 systemd:

```bash
# Nginx 服务管理
/etc/init.d/nginx start      # 启动
/etc/init.d/nginx stop       # 停止
/etc/init.d/nginx restart    # 重启
/etc/init.d/nginx status     # 查看状态

# PHP-FPM 服务管理 (PHP 8.3)
/etc/init.d/php-fpm-83 start      # 启动
/etc/init.d/php-fpm-83 stop       # 停止
/etc/init.d/php-fpm-83 restart    # 重启
/etc/init.d/php-fpm-83 status     # 查看状态
```

**重要**: 不要使用 `systemctl start nginx`,这会导致配置文件冲突。

### 开机自启设置

宝塔服务开机自启:

```bash
# 设置 Nginx 开机自启
update-rc.d nginx defaults

# 验证开机自启
ls -la /etc/rc*.d/*nginx
```

### 配置文件路径

宝塔面板的配置文件路径:

```
Nginx 主配置:     /www/server/nginx/conf/nginx.conf
站点配置:         /www/server/panel/vhost/nginx/*.conf
SSL 证书:         /www/server/panel/vhost/ssl/
日志目录:         /www/wwwlogs/
```

### 快速启动检查清单

服务器重启后的启动流程:

```bash
# 1. 检查 Nginx 状态
/etc/init.d/nginx status

# 如果未运行,启动 Nginx
/etc/init.d/nginx start

# 2. 检查 PHP-FPM 状态
/etc/init.d/php-fpm-83 status

# 如果未运行,启动 PHP-FPM
/etc/init.d/php-fpm-83 start

# 3. 验证 API 服务
curl https://dream-api.newpai.cn/health

# 预期返回:
# {"status":"ok","gateway":"MLGateway","timestamp":"...","origin":"https://dream-api.sendto.you"}
```

### 常见问题

#### 问题 1: systemctl 启动 Nginx 失败

**错误信息**:
```
Job for nginx.service failed because the control process exited with error code.
unknown directive "stream" in /etc/nginx/nginx.conf
```

**原因**: systemctl 使用的 `/etc/nginx/nginx.conf` 与宝塔的 `/www/server/nginx/conf/nginx.conf` 冲突。

**解决方案**: 使用宝塔的 init.d 脚本:
```bash
/etc/init.d/nginx start
```

#### 问题 2: 服务未开机自启

**检查方法**:
```bash
# 检查服务是否开机自启
systemctl is-enabled nginx
```

**解决方案**:
```bash
# 使用 update-rc.d 设置开机自启
update-rc.d nginx defaults
update-rc.d php-fpm-83 defaults
```

#### 问题 3: 域名无法访问

**排查步骤**:
```bash
# 1. 检查 Nginx 是否运行
ps aux | grep nginx

# 2. 检查端口监听
netstat -tlnp | grep -E ':80|:443'

# 3. 测试本地访问
curl -I http://127.0.0.1

# 4. 检查防火墙
# 宝塔面板: 安全 -> 防火墙 -> 确保 80 和 443 端口开放
```

### 宝塔面板配置建议

1. **PHP 版本**: 确保 PHP 8.3 已安装并在网站设置中选择
2. **网站目录**: 指向 `/www/wwwroot/maliang_gateway/public`
3. **运行目录**: 设置为 `/public`
4. **伪静态**: 选择 `laravel5`
5. **SSL**: 在网站设置中配置 Let's Encrypt 证书

### 一键启动脚本

创建 `/www/wwwroot/maliang_gateway/start-services.sh`:

```bash
#!/bin/bash

echo "=== 启动 MLGateway 服务 ==="

# 启动 Nginx
if ! /etc/init.d/nginx status > /dev/null 2>&1; then
    echo "启动 Nginx..."
    /etc/init.d/nginx start
else
    echo "Nginx 已运行"
fi

# 启动 PHP-FPM
if ! /etc/init.d/php-fpm-83 status > /dev/null 2>&1; then
    echo "启动 PHP-FPM..."
    /etc/init.d/php-fpm-83 start
else
    echo "PHP-FPM 已运行"
fi

# 健康检查
echo ""
echo "=== 健康检查 ==="
curl -s https://dream-api.newpai.cn/health | jq .

echo ""
echo "✅ 所有服务已启动"
```

赋予执行权限:
```bash
chmod +x /www/wwwroot/maliang_gateway/start-services.sh
```

使用:
```bash
./start-services.sh
```

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
