#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  AI Trading Platform — Full Server Setup for CentOS / AlmaLinux / Rocky Linux
#  يعمل على أي distro بيستخدم dnf أو yum
#
#  الاستخدام:
#    sudo bash install_server_centos.sh
# ═══════════════════════════════════════════════════════════════════════════════

set -e

CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
RED='\033[0;31m'; BOLD='\033[1m'; NC='\033[0m'

log()  { echo -e "\n${CYAN}━━━ $1 ${NC}"; }
ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC}  $1"; }
err()  { echo -e "  ${RED}✗${NC} $1"; exit 1; }
step() { echo -e "  ${BOLD}→${NC} $1"; }

# ─── Must run as root ─────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "Run as root: sudo bash $0"

# ─── Detect package manager ───────────────────────────────────────────────────
if command -v dnf &>/dev/null; then
    PKG="dnf"
elif command -v yum &>/dev/null; then
    PKG="yum"
else
    err "No supported package manager found (dnf/yum/apt-get)"
fi
ok "Package manager: $PKG"

# ─── Config ───────────────────────────────────────────────────────────────────
PROJECT_DIR="/var/www/ai-trading"
PYTHON_DIR="$PROJECT_DIR/python-ta-service"
PHP_VERSION="8.3"
PYTHON_VERSION="3.11"
SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

echo -e "\n${BOLD}${CYAN}"
echo "  ╔═══════════════════════════════════════════╗"
echo "  ║  AI Trading — CentOS/AlmaLinux Installer  ║"
echo "  ╚═══════════════════════════════════════════╝"
echo -e "${NC}"
echo "  Server IP : $SERVER_IP"
echo "  Project   : $PROJECT_DIR"
echo ""

# ═══════════════════════════════════════════════════════════════════════════════
log "1. System Update & Base Tools"
# ═══════════════════════════════════════════════════════════════════════════════
$PKG update -y -q
$PKG install -y -q \
    curl wget git unzip zip nano \
    openssl ca-certificates \
    epel-release
ok "Base tools installed"

# ═══════════════════════════════════════════════════════════════════════════════
log "2. PHP $PHP_VERSION"
# ═══════════════════════════════════════════════════════════════════════════════
# Add Remi repository (best PHP source for RHEL-based)
step "Adding Remi repository..."
$PKG install -y -q https://rpms.remirepo.net/enterprise/remi-release-$(rpm -E '%{rhel}').rpm 2>/dev/null || \
$PKG install -y -q https://rpms.remirepo.net/fedora/remi-release-$(rpm -E '%{fedora}').rpm 2>/dev/null || true

$PKG module reset php -y 2>/dev/null || true
$PKG module enable php:remi-${PHP_VERSION} -y 2>/dev/null || true

$PKG install -y -q \
    php \
    php-fpm \
    php-cli \
    php-mysqlnd \
    php-pdo \
    php-mbstring \
    php-xml \
    php-curl \
    php-zip \
    php-bcmath \
    php-redis \
    php-gd \
    php-intl \
    php-opcache \
    php-json \
    php-sodium

ok "PHP installed: $(php -r 'echo PHP_VERSION;')"

# ═══════════════════════════════════════════════════════════════════════════════
log "3. Composer"
# ═══════════════════════════════════════════════════════════════════════════════
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
ok "Composer: $(composer --version --no-ansi | head -1)"

# ═══════════════════════════════════════════════════════════════════════════════
log "4. MySQL (MariaDB)"
# ═══════════════════════════════════════════════════════════════════════════════
$PKG install -y -q mariadb-server mariadb
systemctl enable mariadb
systemctl start mariadb

# Secure & create DB
DB_PASS=$(openssl rand -base64 20 | tr -dc 'a-zA-Z0-9' | head -c16)

mysql -e "
CREATE DATABASE IF NOT EXISTS ai_automation_trading CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ai_trading'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ai_automation_trading.* TO 'ai_trading'@'localhost';
FLUSH PRIVILEGES;
" 2>/dev/null || mysql -u root -e "
CREATE DATABASE IF NOT EXISTS ai_automation_trading CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ai_trading'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ai_automation_trading.* TO 'ai_trading'@'localhost';
FLUSH PRIVILEGES;
"

ok "MariaDB ready — DB: ai_automation_trading | User: ai_trading | Pass: ${DB_PASS}"

# ═══════════════════════════════════════════════════════════════════════════════
log "5. Redis"
# ═══════════════════════════════════════════════════════════════════════════════
$PKG install -y -q redis
systemctl enable redis
systemctl start redis
ok "Redis: $(redis-cli ping)"

# ═══════════════════════════════════════════════════════════════════════════════
log "6. Nginx"
# ═══════════════════════════════════════════════════════════════════════════════
$PKG install -y -q nginx
systemctl enable nginx

# ─── Nginx config ─────────────────────────────────────────────────────────────
mkdir -p /etc/nginx/conf.d/
cat > /etc/nginx/conf.d/ai-trading.conf << NGINX_EOF
server {
    listen 80;
    server_name ${SERVER_IP} _;

    root ${PROJECT_DIR}/public;
    index index.php;

    fastcgi_read_timeout 300;
    proxy_read_timeout 300;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass   unix:/run/php-fpm/www.sock;
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    # Laravel Reverb WebSocket
    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_cache_bypass \$http_upgrade;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, no-transform";
    }

    location ~ /\.(env|git|htaccess) { deny all; }

    client_max_body_size 50M;
    charset utf-8;

    access_log /var/log/nginx/ai-trading-access.log;
    error_log  /var/log/nginx/ai-trading-error.log;
}
NGINX_EOF

# Fix PHP-FPM socket path for CentOS
PHP_SOCK=$(find /run /var/run -name "*.sock" 2>/dev/null | grep -i php | head -1 || echo "/run/php-fpm/www.sock")
sed -i "s|unix:/run/php-fpm/www.sock|unix:${PHP_SOCK}|g" /etc/nginx/conf.d/ai-trading.conf

systemctl start nginx
nginx -t && systemctl reload nginx
ok "Nginx configured → http://${SERVER_IP}"

# ─── SELinux: allow Nginx to connect to PHP-FPM & ports ──────────────────────
if command -v setsebool &>/dev/null; then
    setsebool -P httpd_can_network_connect 1 2>/dev/null || true
    setsebool -P httpd_execmem 1 2>/dev/null || true
    semanage port -a -t http_port_t -p tcp 8080 2>/dev/null || true
fi

# ─── Firewall ─────────────────────────────────────────────────────────────────
if command -v firewall-cmd &>/dev/null; then
    firewall-cmd --permanent --add-service=http 2>/dev/null || true
    firewall-cmd --permanent --add-service=https 2>/dev/null || true
    firewall-cmd --permanent --add-port=8001/tcp 2>/dev/null || true
    firewall-cmd --permanent --add-port=8080/tcp 2>/dev/null || true
    firewall-cmd --reload 2>/dev/null || true
    ok "Firewall ports opened (80, 443, 8001, 8080)"
fi

# ═══════════════════════════════════════════════════════════════════════════════
log "7. Python $PYTHON_VERSION"
# ═══════════════════════════════════════════════════════════════════════════════
# Try to install Python 3.11 from available repos
$PKG install -y -q python${PYTHON_VERSION//.} 2>/dev/null || \
$PKG install -y -q python3.11 2>/dev/null || \
$PKG install -y -q python311 2>/dev/null || {
    # Build from source as fallback
    warn "Python 3.11 not in repos — compiling from source (5-10 min)..."
    $PKG install -y -q gcc openssl-devel bzip2-devel libffi-devel zlib-devel xz-devel
    cd /tmp
    wget -q https://www.python.org/ftp/python/3.11.9/Python-3.11.9.tgz
    tar xzf Python-3.11.9.tgz
    cd Python-3.11.9
    ./configure --enable-optimizations --with-ensurepip=install -q
    make -j$(nproc) -s
    make altinstall -s
    cd /
    ok "Python 3.11 compiled from source"
}

$PKG install -y -q gcc gcc-c++ libgomp 2>/dev/null || true

PYTHON_BIN=$(command -v python3.11 || command -v python3 || echo "python3")
ok "Python: $($PYTHON_BIN --version)"

# ═══════════════════════════════════════════════════════════════════════════════
log "8. Laravel Setup"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PROJECT_DIR"

# Write .env
cat > .env << ENV_EOF
APP_NAME="AI Trading Platform"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://${SERVER_IP}

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ai_automation_trading
DB_USERNAME=ai_trading
DB_PASSWORD=${DB_PASS}

SESSION_DRIVER=database
SESSION_LIFETIME=120

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=aitrading_prod
REVERB_APP_KEY=$(openssl rand -hex 16)
REVERB_APP_SECRET=$(openssl rand -hex 16)
REVERB_HOST=${SERVER_IP}
REVERB_PORT=8080
REVERB_SCHEME=http

FILESYSTEM_DISK=local
QUEUE_CONNECTION=database
CACHE_STORE=redis

REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

MAIL_MAILER=log

# Python TA Microservice
TA_SERVICE_URL=http://127.0.0.1:8001

# ⬇ Add your keys here
OPENROUTER_API_KEY=
OPENROUTER_DEFAULT_MODEL=
OPENROUTER_BASE_URL=

BINANCE_API_KEY=
BINANCE_API_SECRET=

CRYPTOPANIC_API_KEY=
NEWSDATA_API_KEY=
WHALE_ALERT_API_KEY=

FRONTEND_URL=http://${SERVER_IP}
ENV_EOF

step "Composer install..."
composer install --no-dev --optimize-autoloader --no-interaction -q

step "App key & migrations..."
php artisan key:generate --force
php artisan migrate --force

step "Caching..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Permissions
useradd -r nginx 2>/dev/null || true
chown -R nginx:nginx "$PROJECT_DIR" 2>/dev/null || \
chown -R www-data:www-data "$PROJECT_DIR" 2>/dev/null || true
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"

ok "Laravel ready"

# ═══════════════════════════════════════════════════════════════════════════════
log "9. Python TA Service"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PYTHON_DIR"

step "Creating venv..."
$PYTHON_BIN -m venv venv

step "Installing packages (قد يأخذ 3-5 دقائق)..."
./venv/bin/pip install --no-cache-dir --upgrade pip -q
./venv/bin/pip install --no-cache-dir -r requirements.txt

cat > .env << PYENV_EOF
BINANCE_API_KEY=
BINANCE_API_SECRET=
REDIS_URL=redis://127.0.0.1:6379
CACHE_TTL_SECONDS=60
PYENV_EOF

ok "Python packages installed"

# ─── Systemd: Python TA ───────────────────────────────────────────────────────
cat > /etc/systemd/system/ai-trading-python.service << SVC_EOF
[Unit]
Description=AI Trading — Python TA Microservice
After=network.target redis.service
Wants=redis.service

[Service]
Type=simple
User=root
WorkingDirectory=${PYTHON_DIR}
EnvironmentFile=${PYTHON_DIR}/.env
ExecStart=${PYTHON_DIR}/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001 --workers 2
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=ai-trading-python

[Install]
WantedBy=multi-user.target
SVC_EOF

systemctl daemon-reload
systemctl enable ai-trading-python
systemctl start ai-trading-python
ok "Python TA started"

# ═══════════════════════════════════════════════════════════════════════════════
log "10. Laravel Queue Worker"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PROJECT_DIR"

cat > /etc/systemd/system/ai-trading-queue.service << QUEUE_EOF
[Unit]
Description=AI Trading — Laravel Queue Worker
After=network.target mariadb.service redis.service

[Service]
Type=simple
User=root
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --timeout=90
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=ai-trading-queue

[Install]
WantedBy=multi-user.target
QUEUE_EOF

systemctl daemon-reload
systemctl enable ai-trading-queue
systemctl start ai-trading-queue
ok "Queue worker started"

# ═══════════════════════════════════════════════════════════════════════════════
log "11. Laravel Reverb WebSocket"
# ═══════════════════════════════════════════════════════════════════════════════

cat > /etc/systemd/system/ai-trading-reverb.service << REVERB_EOF
[Unit]
Description=AI Trading — Laravel Reverb WebSocket
After=network.target mariadb.service redis.service

[Service]
Type=simple
User=root
WorkingDirectory=${PROJECT_DIR}
ExecStart=/usr/bin/php artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal
SyslogIdentifier=ai-trading-reverb

[Install]
WantedBy=multi-user.target
REVERB_EOF

systemctl daemon-reload
systemctl enable ai-trading-reverb
systemctl start ai-trading-reverb
ok "Reverb WebSocket started"

# ═══════════════════════════════════════════════════════════════════════════════
log "12. Health Checks"
# ═══════════════════════════════════════════════════════════════════════════════
sleep 6

check_svc() {
    systemctl is-active --quiet "$2" \
        && ok "$1 ✅" \
        || warn "$1 ❌  →  journalctl -u $2 -n 30 --no-pager"
}

check_svc "PHP-FPM"       "php-fpm"
check_svc "Nginx"         "nginx"
check_svc "MariaDB"       "mariadb"
check_svc "Redis"         "redis"
check_svc "Python TA"     "ai-trading-python"
check_svc "Queue Worker"  "ai-trading-queue"
check_svc "Reverb WS"     "ai-trading-reverb"

PYTHON_HTTP=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8001/health 2>/dev/null || echo "000")
[ "$PYTHON_HTTP" = "200" ] \
    && ok "Python /health HTTP 200 ✅" \
    || warn "Python /health → $PYTHON_HTTP (give it a few seconds)"

# ═══════════════════════════════════════════════════════════════════════════════
echo -e "\n${GREEN}${BOLD}"
echo "  ╔══════════════════════════════════════════════════════╗"
echo "  ║           ✅ Installation Complete!                  ║"
echo "  ╠══════════════════════════════════════════════════════╣"
printf "  ║  🌐  Laravel API  → http://%-26s║\n" "${SERVER_IP}"
printf "  ║  🐍  Python TA    → http://%-22s║\n" "${SERVER_IP}:8001"
printf "  ║  📡  WebSocket    → ws://%-23s║\n"  "${SERVER_IP}:8080"
echo "  ╠══════════════════════════════════════════════════════╣"
printf "  ║  🔑  DB Password  → %-32s║\n" "${DB_PASS}"
echo "  ╠══════════════════════════════════════════════════════╣"
echo "  ║  📝  Edit keys: nano ${PROJECT_DIR}/.env"
echo "  ║  📝  Binance:   nano ${PYTHON_DIR}/.env"
echo "  ╚══════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""
echo -e "${CYAN}أوامر مفيدة:${NC}"
echo "  journalctl -u ai-trading-python -f    # Python logs"
echo "  journalctl -u ai-trading-queue  -f    # Queue logs"
echo "  journalctl -u ai-trading-reverb -f    # WebSocket logs"
echo "  systemctl restart ai-trading-python   # Restart Python"
echo ""
