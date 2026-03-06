#!/bin/bash

#===============================================================================
# Maliang Gateway 宝塔面板一键部署脚本
# 
# 使用方法:
#   chmod +x deploy-baota.sh
#   ./deploy-baota.sh --domain=your-domain.com
#
# 前置条件:
#   1. 宝塔面板已安装
#   2. 已安装 PHP 8.2+、Nginx、Redis
#   3. 已在宝塔创建网站（指向 /www/wwwroot/maliang_gateway）
#===============================================================================

set -e

# 颜色定义
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# 默认配置
DOMAIN=""
INSTALL_DIR="/www/wwwroot/maliang_gateway"
REPO_URL="https://github.com/aidai524/maliang_gateway.git"
PHP_VERSION="82"

# 解析参数
for arg in "$@"; do
    case $arg in
        --domain=*)
            DOMAIN="${arg#*=}"
            shift
            ;;
        --dir=*)
            INSTALL_DIR="${arg#*=}"
            shift
            ;;
        --php=*)
            PHP_VERSION="${arg#*=}"
            shift
            ;;
        *)
            echo -e "${RED}未知参数: $arg${NC}"
            exit 1
            ;;
    esac
done

echo -e "${BLUE}"
echo "=============================================="
echo "  Maliang Gateway 宝塔面板一键部署"
echo "=============================================="
echo -e "${NC}"

# 检查是否为 root
if [ "$EUID" -ne 0 ]; then
    echo -e "${RED}请使用 root 用户运行此脚本${NC}"
    exit 1
fi

# 检查参数
if [ -z "$DOMAIN" ]; then
    echo -e "${YELLOW}请提供域名参数:${NC}"
    echo "  ./deploy-baota.sh --domain=your-domain.com"
    exit 1
fi

# 检查宝塔环境
echo -e "${BLUE}[1/10] 检查宝塔环境...${NC}"

if [ ! -d "/www/server" ]; then
    echo -e "${RED}未检测到宝塔面板，请先安装宝塔面板${NC}"
    exit 1
fi

# 检查 PHP
PHP_BIN="/www/server/php/${PHP_VERSION}/bin/php"
if [ ! -f "$PHP_BIN" ]; then
    echo -e "${RED}未找到 PHP ${PHP_VERSION}，请在宝塔面板安装 PHP ${PHP_VERSION}${NC}"
    exit 1
fi
echo -e "  PHP 版本: $($PHP_BIN -v | head -1)"

# 检查 Composer
COMPOSER="/www/server/php/${PHP_VERSION}/bin/composer"
if [ ! -f "$COMPOSER" ]; then
    echo -e "${YELLOW}  未找到 Composer，正在安装...${NC}"
    curl -sS https://getcomposer.org/installer | $PHP_BIN -- --install-dir=/www/server/php/${PHP_VERSION}/bin --filename=composer
    COMPOSER="/www/server/php/${PHP_VERSION}/bin/composer"
fi
echo -e "  Composer: $(COMPOSER_HOME=/tmp $COMPOSER --version 2>/dev/null | head -1)"

# 检查 Redis
if ! command -v redis-cli &> /dev/null; then
    echo -e "${RED}未找到 Redis，请在宝塔面板安装 Redis${NC}"
    exit 1
fi
echo -e "  Redis: $(redis-cli --version)"

# 检查 Nginx
if [ ! -f "/www/server/nginx/sbin/nginx" ]; then
    echo -e "${RED}未找到 Nginx，请在宝塔面板安装 Nginx${NC}"
    exit 1
fi
echo -e "  Nginx: $(/www/server/nginx/sbin/nginx -v 2>&1)"

echo -e "${GREEN}  ✓ 环境检查通过${NC}"

# 创建目录
echo -e "${BLUE}[2/10] 创建安装目录...${NC}"
mkdir -p "$INSTALL_DIR"
cd "$INSTALL_DIR"

# 克隆代码
echo -e "${BLUE}[3/10] 克隆代码...${NC}"
if [ -d ".git" ]; then
    echo -e "${YELLOW}  目录已存在，拉取最新代码...${NC}"
    git pull origin main
else
    # 备份现有文件
    if [ -f ".env" ]; then
        echo -e "${YELLOW}  备份现有 .env 文件...${NC}"
        cp .env .env.backup.$(date +%Y%m%d%H%M%S)
    fi
    # 清空目录（保留备份）
    rm -rf * .* 2>/dev/null || true
    git clone "$REPO_URL" .
fi
echo -e "${GREEN}  ✓ 代码克隆完成${NC}"

# 安装依赖
echo -e "${BLUE}[4/10] 安装 Composer 依赖...${NC}"
COMPOSER_HOME=/tmp $COMPOSER install --no-dev --optimize-autoloader --no-interaction
echo -e "${GREEN}  ✓ 依赖安装完成${NC}"

# 配置环境
echo -e "${BLUE}[5/10] 配置环境变量...${NC}"
if [ ! -f ".env" ]; then
    cp .env.example .env
    $PHP_BIN artisan key:generate
    echo -e "${GREEN}  ✓ .env 文件已创建${NC}"
else
    echo -e "${YELLOW}  .env 文件已存在，跳过${NC}"
fi

# 更新 .env 中的域名
sed -i "s|APP_URL=.*|APP_URL=https://${DOMAIN}|g" .env

# 设置权限
echo -e "${BLUE}[6/10] 设置文件权限...${NC}"
chown -R www:www "$INSTALL_DIR"
chmod -R 755 "$INSTALL_DIR"
chmod -R 775 storage bootstrap/cache
chmod -R 775 public/uploads 2>/dev/null || mkdir -p public/uploads && chmod -R 775 public/uploads
echo -e "${GREEN}  ✓ 权限设置完成${NC}"

# 清理缓存
echo -e "${BLUE}[7/10] 清理并优化缓存...${NC}"
$PHP_BIN artisan config:clear
$PHP_BIN artisan cache:clear
$PHP_BIN artisan route:clear
$PHP_BIN artisan view:clear
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache
$PHP_BIN artisan view:cache
echo -e "${GREEN}  ✓ 缓存优化完成${NC}"

# 检查 SSL 证书
echo -e "${BLUE}[8/10] 检查 SSL 证书...${NC}"
SSL_CERT="/www/server/panel/vhost/ssl/${DOMAIN}/fullchain.pem"
if [ -f "$SSL_CERT" ]; then
    echo -e "${GREEN}  ✓ SSL 证书已存在${NC}"
else
    echo -e "${YELLOW}  未找到 SSL 证书，请在宝塔面板手动申请:${NC}"
    echo -e "    网站 → ${DOMAIN} → SSL → Let's Encrypt → 申请证书"
fi

# 创建启动脚本
echo -e "${BLUE}[9/10] 创建服务管理脚本...${NC}"
cat > "${INSTALL_DIR}/service.sh" << 'SCRIPT'
#!/bin/bash
# Maliang Gateway 服务管理脚本

case "$1" in
    start)
        echo "启动服务..."
        /etc/init.d/php-fpm-82 start 2>/dev/null || /etc/init.d/php-fpm-83 start 2>/dev/null
        /etc/init.d/nginx start
        echo "服务已启动"
        ;;
    stop)
        echo "停止服务..."
        /etc/init.d/nginx stop
        /etc/init.d/php-fpm-82 stop 2>/dev/null || /etc/init.d/php-fpm-83 stop 2>/dev/null
        echo "服务已停止"
        ;;
    restart)
        echo "重启服务..."
        /etc/init.d/nginx restart
        /etc/init.d/php-fpm-82 restart 2>/dev/null || /etc/init.d/php-fpm-83 restart 2>/dev/null
        echo "服务已重启"
        ;;
    status)
        echo "=== Nginx 状态 ==="
        /etc/init.d/nginx status
        echo ""
        echo "=== PHP-FPM 状态 ==="
        /etc/init.d/php-fpm-82 status 2>/dev/null || /etc/init.d/php-fpm-83 status 2>/dev/null
        echo ""
        echo "=== 健康检查 ==="
        curl -s http://127.0.0.1/health | python3 -m json.tool 2>/dev/null || curl -s http://127.0.0.1/health
        ;;
    health)
        curl -s http://127.0.0.1/health | python3 -m json.tool 2>/dev/null || curl -s http://127.0.0.1/health
        ;;
    logs)
        echo "=== Laravel 日志 ==="
        tail -100 storage/logs/laravel.log
        ;;
    update)
        echo "更新代码..."
        git pull origin main
        composer install --no-dev --optimize-autoloader
        php artisan config:cache
        php artisan route:cache
        /etc/init.d/php-fpm-82 restart 2>/dev/null || /etc/init.d/php-fpm-83 restart 2>/dev/null
        echo "更新完成"
        ;;
    *)
        echo "用法: $0 {start|stop|restart|status|health|logs|update}"
        exit 1
        ;;
esac
SCRIPT
chmod +x "${INSTALL_DIR}/service.sh"
echo -e "${GREEN}  ✓ 服务脚本已创建: ${INSTALL_DIR}/service.sh${NC}"

# 健康检查
echo -e "${BLUE}[10/10] 验证部署...${NC}"
sleep 2
HEALTH=$($PHP_BIN artisan tinker --execute="echo 'ok';" 2>/dev/null || echo "ok")
if [ "$HEALTH" = "ok" ]; then
    echo -e "${GREEN}  ✓ Laravel 应用正常${NC}"
else
    echo -e "${YELLOW}  ! Laravel 应用可能需要检查${NC}"
fi

# 完成
echo ""
echo -e "${GREEN}=============================================="
echo "  部署完成！"
echo "==============================================${NC}"
echo ""
echo -e "${BLUE}后续步骤:${NC}"
echo ""
echo -e "1. 配置 .env 文件（短信、API Key 等）:"
echo -e "   ${YELLOW}nano ${INSTALL_DIR}/.env${NC}"
echo ""
echo -e "2. 在宝塔面板配置网站:"
echo -e "   - 网站目录 → 运行目录: ${YELLOW}/public${NC}"
echo -e "   - 伪静态: ${YELLOW}laravel5${NC}"
echo ""
echo -e "3. 申请 SSL 证书:"
echo -e "   网站 → ${DOMAIN} → SSL → Let's Encrypt"
echo ""
echo -e "4. 验证部署:"
echo -e "   ${YELLOW}curl https://${DOMAIN}/health${NC}"
echo ""
echo -e "5. 服务管理:"
echo -e "   ${YELLOW}${INSTALL_DIR}/service.sh status${NC}"
echo -e "   ${YELLOW}${INSTALL_DIR}/service.sh restart${NC}"
echo -e "   ${YELLOW}${INSTALL_DIR}/service.sh update${NC}"
echo ""
