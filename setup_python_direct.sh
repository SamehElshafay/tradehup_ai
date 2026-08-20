#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  Python TA Service — بدون Docker — تشغيل مباشر على Linux
#  Run:  chmod +x setup_python_direct.sh && sudo ./setup_python_direct.sh
#
#  بيعمل:
#   1. يثبت Python 3.11
#   2. ينشئ venv جديد
#   3. يثبت الـ dependencies
#   4. يشغل الـ service كـ systemd (يبقى شغال دايماً ويرجع لو وقع)
# ═══════════════════════════════════════════════════════════════════════════════

set -e

CYAN='\033[0;36m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'; NC='\033[0m'
log() { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()  { echo -e "${GREEN}[OK]${NC}   $1"; }
warn(){ echo -e "${YELLOW}[WARN]${NC} $1"; }

# ── الـ path للـ service ──────────────────────────────────────────────────────
SERVICE_DIR="/opt/ai-trading/python-ta-service"

if [ ! -d "$SERVICE_DIR" ]; then
    warn "Directory $SERVICE_DIR not found!"
    warn "Upload your project to /opt/ai-trading first, then re-run."
    exit 1
fi

# ── 1. Install Python 3.11 ────────────────────────────────────────────────────
log "Installing Python 3.11..."
apt-get update -qq
apt-get install -y python3.11 python3.11-venv python3.11-dev \
    gcc g++ libgomp1 curl
ok "Python 3.11 installed: $(python3.11 --version)"

# ── 2. Create virtual environment ─────────────────────────────────────────────
log "Creating virtual environment..."
cd "$SERVICE_DIR"
rm -rf venv
python3.11 -m venv venv
ok "venv created"

# ── 3. Install requirements ───────────────────────────────────────────────────
log "Installing Python requirements (may take 2-5 minutes)..."
./venv/bin/pip install --no-cache-dir --upgrade pip
./venv/bin/pip install --no-cache-dir -r requirements.txt
ok "All packages installed"

# ── 4. Create systemd service ─────────────────────────────────────────────────
log "Creating systemd service..."

cat > /etc/systemd/system/ai-trading-python.service << EOF
[Unit]
Description=AI Trading — Python TA Microservice
After=network.target redis.service
Wants=redis.service

[Service]
Type=simple
User=www-data
WorkingDirectory=$SERVICE_DIR
ExecStart=$SERVICE_DIR/venv/bin/uvicorn main:app --host 0.0.0.0 --port 8001 --workers 2
Restart=always
RestartSec=5
EnvironmentFile=$SERVICE_DIR/.env

# Memory & process limits
MemoryMax=1G
LimitNOFILE=65536

# Logging
StandardOutput=journal
StandardError=journal
SyslogIdentifier=ai-trading-python

[Install]
WantedBy=multi-user.target
EOF

# Fix ownership
chown -R www-data:www-data "$SERVICE_DIR" 2>/dev/null || true

# ── 5. Enable & start ─────────────────────────────────────────────────────────
log "Starting service..."
systemctl daemon-reload
systemctl enable ai-trading-python
systemctl restart ai-trading-python

sleep 4

# ── 6. Health check ───────────────────────────────────────────────────────────
STATUS=$(systemctl is-active ai-trading-python)
if [ "$STATUS" = "active" ]; then
    ok "✅ Python TA Service is running!"
    HEALTH=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8001/health 2>/dev/null || echo "000")
    if [ "$HEALTH" = "200" ]; then
        ok "✅ Health check passed → http://localhost:8001"
    else
        warn "Service running but /health returned $HEALTH — check logs"
    fi
else
    echo ""
    warn "❌ Service failed to start. Check logs with:"
    echo "   journalctl -u ai-trading-python -n 50 --no-pager"
    echo ""
    journalctl -u ai-trading-python -n 30 --no-pager
fi

echo ""
ok "═══════════════════════════════════════════════"
ok " Useful commands:"
echo "  systemctl status ai-trading-python       # Status"
echo "  systemctl restart ai-trading-python      # Restart"
echo "  journalctl -u ai-trading-python -f       # Live logs"
ok "═══════════════════════════════════════════════"
