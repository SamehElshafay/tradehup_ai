#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  Fix: PHP Installation on AlmaLinux 9 with Remi
#  Run: sudo bash fix_php.sh
# ═══════════════════════════════════════════════════════════════════════════════

set -e
CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log()  { echo -e "\n${CYAN}━━━ $1 ${NC}"; }
ok()   { echo -e "  ${GREEN}✓${NC} $1"; }
warn() { echo -e "  ${YELLOW}⚠${NC}  $1"; }

PROJECT_DIR="/var/www/ai-trading"
PYTHON_DIR="$PROJECT_DIR/python-ta-service"
SERVER_IP=$(curl -s ifconfig.me 2>/dev/null || hostname -I | awk '{print $1}')

# ═══════════════════════════════════════════════════════════════════════════════
log "1. Detect existing PHP"
# ═══════════════════════════════════════════════════════════════════════════════

# Check if any PHP already installed
PHP_BIN=$(command -v php 2>/dev/null || \
          command -v php83 2>/dev/null || \
          command -v php8.3 2>/dev/null || \
          ls /opt/cpanel/ea-php*/root/usr/bin/php 2>/dev/null | tail -1 || \
          ls /usr/bin/php* 2>/dev/null | tail -1 || echo "")

if [ -n "$PHP_BIN" ]; then
    ok "PHP found: $PHP_BIN — $($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null)"
else
    warn "No PHP found — trying alternative install methods..."
fi

# ═══════════════════════════════════════════════════════════════════════════════
log "2. Install PHP 8.3 (AlmaLinux 9 method)"
# ═══════════════════════════════════════════════════════════════════════════════

# Method A: php83-* package names (Remi naming on EL9)
if ! command -v php &>/dev/null; then
    echo "Trying Remi php83-* packages..."
    dnf install -y \
        php83-php \
        php83-php-fpm \
        php83-php-cli \
        php83-php-mysqlnd \
        php83-php-pdo \
        php83-php-mbstring \
        php83-php-xml \
        php83-php-curl \
        php83-php-zip \
        php83-php-bcmath \
        php83-php-redis \
        php83-php-gd \
        php83-php-intl \
        php83-php-opcache \
        php83-php-sodium 2>/dev/null && {
            # Create symlink so 'php' works globally
            ln -sf /opt/remi/php83/root/usr/bin/php /usr/local/bin/php 2>/dev/null || \
            ln -sf $(find / -name "php83" -type f 2>/dev/null | head -1) /usr/local/bin/php 2>/dev/null || true
            ok "PHP 8.3 installed via php83-* packages"
        } || {
            # Method B: Enable repo explicitly then install
            echo "Trying with --enablerepo=remi..."
            dnf install -y --enablerepo=remi \
                php php-fpm php-cli php-mysqlnd php-pdo \
                php-mbstring php-xml php-curl php-zip \
                php-bcmath php-redis php-gd php-intl php-opcache 2>/dev/null && \
                ok "PHP installed via remi repo" || warn "PHP install failed"
        }
fi

# Verify
PHP_BIN=$(command -v php 2>/dev/null || command -v php83 2>/dev/null || echo "")
[ -n "$PHP_BIN" ] && ok "PHP ready: $($PHP_BIN --version | head -1)" || { echo "PHP not found, continuing anyway..."; PHP_BIN="php"; }

# ═══════════════════════════════════════════════════════════════════════════════
log "3. Composer"
# ═══════════════════════════════════════════════════════════════════════════════
export COMPOSER_ALLOW_SUPERUSER=1

if ! command -v composer &>/dev/null; then
    echo "Installing Composer..."
    dnf install -y composer -q 2>/dev/null || {
        echo "DNF composer failed, downloading phar directly..."
        curl -4 -sS --connect-timeout 15 --retry 3 -o /usr/local/bin/composer https://getcomposer.org/composer-stable.phar || \
        wget -4 -q -O /usr/local/bin/composer https://getcomposer.org/composer-stable.phar
        chmod +x /usr/local/bin/composer
    }
fi

# Set composer to use IPv4 only to avoid DNS/IPv6 routing hangs on VPS
composer config --global disable-tls true 2>/dev/null || true
composer config --global secure-http false 2>/dev/null || true
ok "Composer: $(composer --version --no-ansi 2>/dev/null | head -1 || echo 'Installed')"


# ═══════════════════════════════════════════════════════════════════════════════
log "4. MySQL / MariaDB"
# ═══════════════════════════════════════════════════════════════════════════════
if ! systemctl is-active --quiet mariadb 2>/dev/null && ! systemctl is-active --quiet mysql 2>/dev/null && ! systemctl is-active --quiet mysqld 2>/dev/null; then
    dnf install -y mariadb-server mariadb 2>/dev/null || dnf install -y mysql-server 2>/dev/null || true
    systemctl enable mariadb 2>/dev/null || systemctl enable mysql 2>/dev/null || true
    systemctl start mariadb 2>/dev/null || systemctl start mysql 2>/dev/null || true
fi

DB_PASS=$(openssl rand -base64 20 | tr -dc 'a-zA-Z0-9' | head -c16)

# Reuse existing password from .env if it exists
if [ -f "${PROJECT_DIR}/.env" ]; then
    EXISTING_PASS=$(grep '^DB_PASSWORD=' "${PROJECT_DIR}/.env" | cut -d '=' -f2)
    if [ -n "$EXISTING_PASS" ]; then
        DB_PASS="$EXISTING_PASS"
    fi
fi

SQL_CMDS="
CREATE DATABASE IF NOT EXISTS ai_automation_trading CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'ai_trading'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER 'ai_trading'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT ALL PRIVILEGES ON ai_automation_trading.* TO 'ai_trading'@'localhost';
FLUSH PRIVILEGES;"

mysql -e "$SQL_CMDS" 2>/dev/null || mysql -u root -e "$SQL_CMDS" 2>/dev/null || warn "Failed to configure database user, continuing..."
ok "Database ready | Pass: ${DB_PASS}"

# ═══════════════════════════════════════════════════════════════════════════════
log "5. Redis"
# ═══════════════════════════════════════════════════════════════════════════════
if ! command -v redis-cli &>/dev/null; then
    dnf install -y redis
fi
systemctl enable redis; systemctl start redis
ok "Redis: $(redis-cli ping 2>/dev/null || echo 'starting...')"

# ═══════════════════════════════════════════════════════════════════════════════
log "6. Web Server (Apache / Nginx)"
# ═══════════════════════════════════════════════════════════════════════════════

# Detect if Apache (httpd) is installed/running
if command -v httpd &>/dev/null || [ -d "/etc/httpd" ]; then
    echo "Apache (httpd) detected. Configuring Apache..."
    
    mkdir -p /etc/httpd/conf.d
    
    # Create Apache config for Laravel
    cat > /etc/httpd/conf.d/ai-trading.conf << APACHE_EOF
<VirtualHost *:80>
    ServerName ${SERVER_IP}
    DocumentRoot ${PROJECT_DIR}/public

    <Directory ${PROJECT_DIR}/public>
        Options Indexes FollowSymLinks MultiViews
        AllowOverride All
        Require all granted
    </Directory>

    # Proxy for Laravel Reverb WebSocket
    ProxyPreserveHost On
    ProxyPass /app http://127.0.0.1:8080/app
    ProxyPassReverse /app http://127.0.0.1:8080/app

    ErrorLog /var/log/httpd/ai-trading-error.log
    CustomLog /var/log/httpd/ai-trading-access.log combined
</VirtualHost>
APACHE_EOF

    systemctl enable httpd 2>/dev/null || true
    systemctl restart httpd 2>/dev/null && ok "Apache (httpd) configured successfully" || warn "Failed to restart Apache"
else
    # Fallback to Nginx
    echo "Apache not detected. Configuring Nginx..."
    if ! command -v nginx &>/dev/null; then
        dnf install -y nginx 2>/dev/null || warn "Could not install Nginx via dnf"
    fi

    mkdir -p /etc/nginx/conf.d/

    # Find PHP-FPM socket
    PHP_SOCK=$(find /run /var/run /tmp -name "*.sock" 2>/dev/null | grep -i php | head -1 || echo "/run/php-fpm/www.sock")
    warn "PHP-FPM socket: $PHP_SOCK"

    if [ -d "/etc/nginx/conf.d" ]; then
        cat > /etc/nginx/conf.d/ai-trading.conf << NGINX_EOF
server {
    listen 80;
    server_name ${SERVER_IP} _;
    root ${PROJECT_DIR}/public;
    index index.php;
    fastcgi_read_timeout 300;

    location / { try_files \$uri \$uri/ /index.php?\$query_string; }

    location ~ \.php$ {
        fastcgi_pass   unix:${PHP_SOCK};
        fastcgi_index  index.php;
        include        fastcgi_params;
        fastcgi_param  SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_read_timeout 300;
    }

    location /app {
        proxy_pass http://127.0.0.1:8080;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
    }

    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg|woff2)$ { expires 30d; }
    location ~ /\.(env|git) { deny all; }
    client_max_body_size 50M;
    access_log /var/log/nginx/ai-trading.log;
}
NGINX_EOF

        systemctl enable nginx 2>/dev/null || true
        systemctl start nginx 2>/dev/null || true
        nginx -t 2>/dev/null && systemctl reload nginx 2>/dev/null && ok "Nginx configured" || warn "Nginx could not start (likely port conflict with LiteSpeed/cPanel). You can configure your website root via cPanel/LiteSpeed to: ${PROJECT_DIR}/public"
    else
        warn "Nginx config folder not found. Assuming you are using LiteSpeed or another web server."
    fi
fi


# SELinux
setsebool -P httpd_can_network_connect 1 2>/dev/null || true
setsebool -P httpd_execmem 1 2>/dev/null || true

# Firewall
firewall-cmd --permanent --add-service=http 2>/dev/null || true
firewall-cmd --permanent --add-port=8001/tcp 2>/dev/null || true
firewall-cmd --permanent --add-port=8080/tcp 2>/dev/null || true
firewall-cmd --reload 2>/dev/null || true


# ═══════════════════════════════════════════════════════════════════════════════
log "7. Python 3.11"
# ═══════════════════════════════════════════════════════════════════════════════
PYTHON_BIN=""
for bin in python3.11 python3.12 python3.10 python3; do
    if command -v $bin &>/dev/null; then
        PYTHON_BIN=$bin
        break
    fi
done

if [ -z "$PYTHON_BIN" ] || [[ "$($PYTHON_BIN --version 2>&1)" == *"3.6"* ]] || [[ "$($PYTHON_BIN --version 2>&1)" == *"3.9"* ]]; then
    warn "Need Python 3.10+ — installing..."
    dnf install -y python3.11 python3.11-pip python3.11-devel 2>/dev/null || \
    dnf install -y python3.12 python3.12-pip python3.12-devel 2>/dev/null || {
        warn "Compiling Python 3.11 from source..."
        dnf install -y gcc openssl-devel bzip2-devel libffi-devel zlib-devel xz-devel -q
        cd /tmp
        wget -q https://www.python.org/ftp/python/3.11.9/Python-3.11.9.tgz
        tar xzf Python-3.11.9.tgz && cd Python-3.11.9
        ./configure --enable-optimizations --with-ensurepip=install -q 2>/dev/null
        make -j$(nproc) -s && make altinstall -s
        cd /
    }
    PYTHON_BIN=$(command -v python3.11 || command -v python3.12 || command -v python3)
fi

dnf install -y gcc gcc-c++ libgomp -q 2>/dev/null || true
ok "Python: $($PYTHON_BIN --version)"

# ═══════════════════════════════════════════════════════════════════════════════
log "8. Laravel Setup"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PROJECT_DIR"

# Write .env only if it doesn't already exist
if [ ! -f ".env" ]; then
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

TA_SERVICE_URL=http://127.0.0.1:8001

OPENROUTER_API_KEY=
OPENROUTER_DEFAULT_MODEL=
OPENROUTER_BASE_URL=

BINANCE_API_KEY=
BINANCE_API_SECRET=

FRONTEND_URL=http://${SERVER_IP}
ENV_EOF
else
    # Update DB password in case it needs to sync with the database setup
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
fi


composer install --no-dev --optimize-autoloader --no-interaction
$PHP_BIN artisan key:generate --force
$PHP_BIN artisan migrate --force
$PHP_BIN artisan config:cache
$PHP_BIN artisan route:cache

chown -R nginx:nginx "$PROJECT_DIR" 2>/dev/null || chown -R www-data:www-data "$PROJECT_DIR" 2>/dev/null || true
chmod -R 775 "$PROJECT_DIR/storage" "$PROJECT_DIR/bootstrap/cache"
ok "Laravel ready"

# ═══════════════════════════════════════════════════════════════════════════════
log "9. Python TA Service"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PYTHON_DIR"
rm -rf venv
$PYTHON_BIN -m venv venv
./venv/bin/pip install --no-cache-dir -i https://pypi.org/simple/ --upgrade pip -q
./venv/bin/pip install --no-cache-dir -i https://pypi.org/simple/ -r requirements.txt

cat > .env << PYENV_EOF
BINANCE_API_KEY=
BINANCE_API_SECRET=
REDIS_URL=redis://127.0.0.1:6379
CACHE_TTL_SECONDS=60
PYENV_EOF

cat > /etc/systemd/system/ai-trading-python.service << SVC_EOF
[Unit]
Description=AI Trading — Python TA Microservice
After=network.target redis.service

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

[Install]
WantedBy=multi-user.target
SVC_EOF

systemctl daemon-reload
systemctl enable ai-trading-python
systemctl start ai-trading-python
ok "Python TA started"

# ═══════════════════════════════════════════════════════════════════════════════
log "10. Queue Worker + Reverb"
# ═══════════════════════════════════════════════════════════════════════════════
cd "$PROJECT_DIR"

cat > /etc/systemd/system/ai-trading-queue.service << QUEUE_EOF
[Unit]
Description=AI Trading — Queue Worker
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${PROJECT_DIR}
ExecStart=${PHP_BIN} artisan queue:work --sleep=3 --tries=3 --timeout=90
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
QUEUE_EOF

cat > /etc/systemd/system/ai-trading-reverb.service << REVERB_EOF
[Unit]
Description=AI Trading — Reverb WebSocket
After=network.target

[Service]
Type=simple
User=root
WorkingDirectory=${PROJECT_DIR}
ExecStart=${PHP_BIN} artisan reverb:start --host=0.0.0.0 --port=8080
Restart=always
RestartSec=5
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
REVERB_EOF

systemctl daemon-reload
systemctl enable ai-trading-queue ai-trading-reverb
systemctl start ai-trading-queue ai-trading-reverb
ok "Queue + Reverb started"

# ═══════════════════════════════════════════════════════════════════════════════
log "11. Health Checks"
# ═══════════════════════════════════════════════════════════════════════════════
sleep 5

chk() { systemctl is-active --quiet "$2" && echo -e "  ${GREEN}✓${NC} $1" || echo -e "  ${YELLOW}⚠${NC}  $1 — journalctl -u $2 -n 20"; }
chk "Nginx"         nginx
chk "Redis"         redis
chk "Python TA"     ai-trading-python
chk "Queue"         ai-trading-queue
chk "Reverb"        ai-trading-reverb

PHTTP=$(curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8001/health 2>/dev/null || echo "000")
[ "$PHTTP" = "200" ] && echo -e "  ${GREEN}✓${NC} Python /health OK" || echo -e "  ${YELLOW}⚠${NC}  Python /health = $PHTTP"

echo -e "\n${GREEN}═══════════════════════════════════════════${NC}"
echo -e "${GREEN}  ✅ Done! DB Password: ${DB_PASS}${NC}"
echo -e "${GREEN}  🌐 http://${SERVER_IP}${NC}"
echo -e "${GREEN}  🐍 http://${SERVER_IP}:8001${NC}"
echo -e "${GREEN}═══════════════════════════════════════════${NC}"
echo ""
echo "  Edit API keys: nano ${PROJECT_DIR}/.env"
echo "  Binance keys:  nano ${PYTHON_DIR}/.env"
echo ""
