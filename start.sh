#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  AI Trading Backend — Start / Restart All Services
#  Run after uploading your files:   chmod +x start.sh && ./start.sh
# ═══════════════════════════════════════════════════════════════════════════════

set -e

CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; RED='\033[0;31m'; NC='\033[0m'
log()  { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()   { echo -e "${GREEN}[OK]${NC}   $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }

# ─── Setup .env if missing ─────────────────────────────────────────────────────
if [ ! -f ".env" ]; then
    warn ".env not found — copying from .env.example"
    cp .env.example .env
    warn "Edit .env now and re-run this script!"
    nano .env || vim .env
fi

# ─── Setup Python .env if missing ─────────────────────────────────────────────
if [ ! -f "python-ta-service/.env" ]; then
    warn "python-ta-service/.env not found — creating minimal version"
    cat > python-ta-service/.env <<EOF
BINANCE_API_KEY=
BINANCE_API_SECRET=
REDIS_URL=redis://redis:6379
CACHE_TTL_SECONDS=60
EOF
    warn "Add your Binance API keys to python-ta-service/.env if needed"
fi

# ─── Set permissions ──────────────────────────────────────────────────────────
log "Setting storage permissions..."
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true

# ─── Build & start all containers ─────────────────────────────────────────────
log "Building Docker images (first run takes 3-5 minutes)..."
docker compose build --no-cache python-ta

log "Starting all services..."
docker compose up -d

# Wait for services to be ready
log "Waiting for services to start..."
sleep 8

# ─── Laravel setup ─────────────────────────────────────────────────────────────
log "Running Laravel setup..."
docker compose exec laravel sh -c "
    cd /var/www &&
    php artisan key:generate --no-interaction 2>/dev/null || true &&
    php artisan migrate --force --no-interaction &&
    php artisan config:cache &&
    php artisan route:cache
"

# ─── Health checks ─────────────────────────────────────────────────────────────
log "Checking service health..."

PYTHON_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8001/health 2>/dev/null || echo "000")
if [ "$PYTHON_STATUS" = "200" ]; then
    ok "✅ Python TA Service is UP → http://localhost:8001"
else
    warn "⚠️  Python TA Service returned HTTP $PYTHON_STATUS — check logs:"
    echo "    docker compose logs python-ta --tail=50"
fi

NGINX_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost 2>/dev/null || echo "000")
if [ "$NGINX_STATUS" = "200" ] || [ "$NGINX_STATUS" = "302" ]; then
    ok "✅ Laravel/Nginx is UP → http://localhost"
else
    warn "⚠️  Nginx returned HTTP $NGINX_STATUS — check logs:"
    echo "    docker compose logs nginx --tail=50"
fi

echo ""
ok "═══════════════════════════════════════════"
ok " All services started!"
ok " Laravel API  → http://YOUR_SERVER_IP"
ok " Python TA    → http://YOUR_SERVER_IP:8001"
ok "═══════════════════════════════════════════"
echo ""
log "Useful commands:"
echo "  docker compose logs -f python-ta    # Python logs"
echo "  docker compose logs -f laravel      # Laravel logs"
echo "  docker compose restart python-ta    # Restart Python only"
echo "  docker compose down                 # Stop everything"
echo "  docker compose ps                   # Status"
