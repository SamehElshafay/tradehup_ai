#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  AI Trading Platform — Full Server Setup (NO Docker)
#  يثبّت كل حاجة مباشرة على Ubuntu 20.04 / 22.04 / 24.04
#
#  الاستخدام:
#    1. ارفع المشروع للسيرفر
#    2. chmod +x install_server.sh
#    3. sudo bash install_server.sh
# ═══════════════════════════════════════════════════════════════════════════════

set -e

# ─── Colors ───────────────────────────────────────────────────────────────────
CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
RED='\033[0;31m'; BOLD='\033[1m'; NC='\033[0m'

log()  { echo -e "\n${CYAN}━━━ $1 ${NC}"; }
ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC}  $1"; }
err()  { echo -e "  ${RED}✗${NC} $1"; exit 1; }
step() { echo -e "  ${BOLD}→${NC} $1"; }

# ─── Project Config ───────────────────────────────────────────────────────────
PROJECT_DIR="/var/www/ai-trading"
PYTHON_DIR="$PROJECT_DIR/python-ta-service"
NGINX_CONF="/etc/nginx/sites-available/ai-trading"
PHP_VERSION="8.3"
PYTHON_VERSION="3.11"

# ─── Must run as root ─────────────────────────────────────────────────────────
[[ $EUID -ne 0 ]] && err "Run as root: sudo bash $0"

echo -e "\n${BOLD}${CYAN}"
echo "  ╔═══════════════════════════════════════╗"
echo "  ║  AI Trading — Full Server Installer   ║"
echo "  ╚═══════════════════════════════════════╝"
echo -e "${NC}"

# ═══════════════════════════════════════════════════════════════════════════════
log "1. System Update"
# ═══════════════════════════════════════════════════════════════════════════════
apt-get update -qq
apt-get install -y -qq \
    curl wget git unzip zip nano \
    software-properties-common ca-certificates \
    gnupg lsb-release apt-transport-https
ok "System packages installed"

# ═══════════════════════════════════════════════════════════════════════════════
log "2. PHP $PHP_VERSION + Extensions"
# ═══════════════════════════════════════════════════════════════════════════════
add-apt-repository -y ppa:ondrej/php > /dev/null 2>&1
apt-get update -qq
apt-get install -y -qq \
    php${PHP_VERSION} \
    php${PHP_VERSION}-fpm \
    php${PHP_VERSION}-cli \
    php${PHP_VERSION}-mysql \
    php${PHP_VERSION}-sqlite3 \
    php${PHP_VERSION}-mbstring \
    php${PHP_VERSION}-xml \
    php${PHP_VERSION}-curl \
    php${PHP_VERSION}-zip \
    php${PHP_VERSION}-bcmath \
    php${PHP_VERSION}-redis \
    php${PHP_VERSION}-gd \
    php${PHP_VERSION}-intl \
    php${PHP_VERSION}-pcov

ok "PHP $PHP_VERSION installed: $(php -r 'echo PHP_VERSION;')"

# ═══════════════════════════════════════════════════════════════════════════════
log "3. Composer"
# ═══════════════════════════════════════════════════════════════════════════════
if ! command -v composer &>/dev/null; then
    curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi
ok "Composer: $(composer --version --no-ansi | head -1)"

# ═══════════════════════════════════════════════════════════════════════════════
log "4. MySQL"
# ═══════════════════════════════════════════════════════════════════════════════
apt-get install -y -qq mysql-server

# Generate random DB password
DB_PASS=$(openssl rand -base64 20 | tr -dc 'a-zA-Z0-9' | head -c16)

systemctl enable mysql
systemctl start mysql

mysql -e "
CREATE DATABASE IF NOT EXISTS ai_automation_trading CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ai_trading'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ai_automation_trading.* TO 'ai_trading'@'localhost';
FLUSH PRIVILEGES;
"
ok "MySQL ready — DB: ai_automation_trading | User: ai_trading | Pass: ${DB_PASS}"
echo "  ${YELLOW}⚠ Save this password! Will be written to .env automatically.${NC}"

# ═══════════════════════════════════════════════════════════════════════════════
log "5. Redis"
# ═══════════════════════════════════════════════════════════════════════════════
apt-get install -y -qq redis-server
systemctl enable redis-server
systemctl start redis-server
ok "Redis running: $(redis-cli ping)"

# ═══════════════════════════════════════════════════════════════════════════════
log "6. Nginx"
# ═══════════════════════════════════════════════════════════════════════════════
apt-get install -y -qq nginx
systemctl enable nginx

# ─── Detect server IP ─────────────────────────────────────────────────────────
SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

# ─── Write Nginx config ───────────────────────────────────────────────────────
cat > "$NGINX_CONF" << NGINX_EOF
server {
    listen 80;
    server_name ${SERVER_IP} _;

    root ${PROJECT_DIR}/public;
    index index.php;

    # Increase timeout for long-running scans
    fastcgi_read_timeout 300;
    proxy_read_timeout 300;

    # Laravel routes
    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass   unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param  PATH_INFO \$fastcgi_path_info;
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

    # Static files cache
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, no-transform";
    }

    # Block sensitive files
    location ~ /\.(env|git|htaccess) { deny all; }

    client_max_body_size 50M;
    charset utf-8;

    # Logs
    access_log /var/log/nginx/ai-trading-access.log;
    error_log  /var/log/nginx/ai-trading-error.log;
}
NGINX_EOF

ln -sf "$NGINX_CONF" /etc/nginx/sites-enabled/ai-trading
rm -f /etc/nginx/sites-enabled/default
nginx -t && systemctl reload nginx
ok "Nginx configured"

# ═══════════════════════════════════════════════════════════════════════════════
log "7. Python $PYTHON_VERSION"
# ═══════════════════════════════════════════════════════════════════════════════
add-apt-repository -y ppa:deadsnakes/python > /dev/null 2>&1 || true
apt-get update -qq
apt-get install -y -qq \
    python${PYTHON_VERSION} \
    python${PYTHON_VERSION}-venv \
    python${PYTHON_VERSION}-dev \
    gcc g++ libgomp1 libffi-dev

ok "Python: $(python${PYTHON_VERSION} --version)"

# ═══════════════════════════════════════════════════════════════════════════════
log "8. Copy Project Files"
# ═══════════════════════════════════════════════════════════════════════════════

# If running from project directory, copy to web root
CURRENT_DIR=$(pwd)
if [ "$CURRENT_DIR" != "$PROJECT_DIR" ]; then
    step "Copying files to $PROJECT_DIR ..."
    mkdir -p "$PROJECT_DIR"
    cp -r . "$PROJECT_DIR/"
    ok "Files copied"
else
    ok "Already in $PROJECT_DIR"
fi

cd "$PROJECT_DIR"

# ═══════════════════════════════════════════════════════════════════════════════
log "9. Laravel Setup"
# ═══════════════════════════════════════════════════════════════════════════════

# Write .env for production
cat > .env << ENV_EOF
APP_NAME="AI Trading Platform"
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=http://${SERVER_IP}

APP_LOCALE=en
APP_FALLBACK_LOCALE=en

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

# Add your API keys below
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

step "Installing Composer dependencies..."
composer install --no-dev --optimize-autoloader --no-interaction -q

step "Generating app key..."
php artisan key:generate --force

step "Running migrations..."
php artisan migrate --force

step "Caching config & routes..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Fix permissions
chown -R www-data:www-data "$PROJECT_DIR"
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"

ok "Laravel ready"

# ═══════════════════════════════════════════════════════════════════════════════
log "10. Python TA Service Setup"
# ═══════════════════════════════════════════════════════════════════════════════

cd "$PYTHON_DIR"

step "Creating virtual environment..."
rm -rf venv
python${PYTHON_VERSION} -m venv venv

step "Installing Python packages (قد يأخذ 3-5 دقائق)..."
./venv/bin/pip install --no-cache-dir --upgrade pip -q
./venv/bin/pip install --no-cache-dir -r requirements.txt

# Write Python .env
cat > .env << PYENV_EOF
BINANCE_API_KEY=
BINANCE_API_SECRET=
REDIS_URL=redis://127.0.0.1:6379
CACHE_TTL_SECONDS=60
PYENV_EOF

ok "Python packages installed"

# ─── Create systemd service for Python TA ─────────────────────────────────────
cat > /etc/systemd/system/ai-trading-python.service << SVC_EOF
[Unit]
Description=AI Trading — Python TA Microservice
After=network.target redis.service
Wants=redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=${PYTHON_DIR}
EnvironmentFile=${PYTHON_DIR}/.env
ExecStart=${PYTHON_DIR}/venv/bin/uvicorn main:app --host 127.0.0.1 --port 8001 --workers 2
Restart=always
RestartSec=5
MemoryMax=1G
StandardOutput=journal
StandardError=journal
SyslogIdentifier=ai-trading-python

[Install]
WantedBy=multi-user.target
SVC_EOF

chown -R www-data:www-data "$PYTHON_DIR"

systemctl daemon-reload
systemctl enable ai-trading-python
systemctl start ai-trading-python
ok "Python TA Service started as systemd service"

# ═══════════════════════════════════════════════════════════════════════════════
log "11. Queue Worker (Laravel)"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PROJECT_DIR"

cat > /etc/systemd/system/ai-trading-queue.service << QUEUE_EOF
[Unit]
Description=AI Trading — Laravel Queue Worker
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
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
log "12. Laravel Reverb (WebSocket)"
# ═══════════════════════════════════════════════════════════════════════════════

cat > /etc/systemd/system/ai-trading-reverb.service << REVERB_EOF
[Unit]
Description=AI Trading — Laravel Reverb WebSocket
After=network.target mysql.service redis.service

[Service]
Type=simple
User=www-data
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
log "13. Health Checks"
# ═══════════════════════════════════════════════════════════════════════════════
sleep 5

check_service() {
    local name=$1
    local svc=$2
    if systemctl is-active --quiet "$svc"; then
        ok "$name ✅"
    else
        warn "$name ❌ — check: journalctl -u $svc -n 30"
    fi
}

check_service "PHP-FPM"         "php${PHP_VERSION}-fpm"
check_service "Nginx"           "nginx"
check_service "MySQL"           "mysql"
check_service "Redis"           "redis-server"
check_service "Python TA"       "ai-trading-python"
check_service "Queue Worker"    "ai-trading-queue"
check_service "Reverb WS"       "ai-trading-reverb"

# Python HTTP health check
PYTHON_HTTP=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8001/health 2>/dev/null || echo "000")
if [ "$PYTHON_HTTP" = "200" ]; then
    ok "Python API /health → HTTP 200 ✅"
else
    warn "Python API returned HTTP $PYTHON_HTTP — may need a few seconds to start"
fi

# ═══════════════════════════════════════════════════════════════════════════════
echo -e "\n${GREEN}${BOLD}"
echo "  ╔═══════════════════════════════════════════════════════╗"
echo "  ║          ✅ Installation Complete!                    ║"
echo "  ╠═══════════════════════════════════════════════════════╣"
echo "  ║  🌐  Laravel API  → http://${SERVER_IP}              "
echo "  ║  🐍  Python TA    → http://${SERVER_IP}:8001         "
echo "  ║  📡  WebSocket    → ws://${SERVER_IP}:8080           "
echo "  ╠═══════════════════════════════════════════════════════╣"
echo "  ║  🔑  DB Password  → ${DB_PASS}                      "
echo "  ╠═══════════════════════════════════════════════════════╣"
echo "  ║  📝  Edit API keys: nano ${PROJECT_DIR}/.env         "
echo "  ║  📝  Binance keys:  nano ${PYTHON_DIR}/.env          "
echo "  ╚═══════════════════════════════════════════════════════╝"
echo -e "${NC}"
echo ""
echo -e "${CYAN}Useful commands:${NC}"
echo "  journalctl -u ai-trading-python -f    # Python logs live"
echo "  journalctl -u ai-trading-queue -f     # Queue logs live"
echo "  journalctl -u ai-trading-reverb -f    # WebSocket logs live"
echo "  systemctl restart ai-trading-python   # Restart Python"
echo "  systemctl status ai-trading-python    # Python status"
echo ""
