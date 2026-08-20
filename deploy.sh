#!/bin/bash
# ═══════════════════════════════════════════════════════════════════════════════
#  AI Trading Backend — Full Server Deployment Script
#  Run this ONCE on a fresh Ubuntu/Debian VPS:
#    chmod +x deploy.sh && sudo ./deploy.sh
# ═══════════════════════════════════════════════════════════════════════════════

set -e  # Exit on any error

CYAN='\033[0;36m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

log()  { echo -e "${CYAN}[INFO]${NC} $1"; }
ok()   { echo -e "${GREEN}[OK]${NC}   $1"; }
warn() { echo -e "${YELLOW}[WARN]${NC} $1"; }
err()  { echo -e "${RED}[ERR]${NC}  $1"; exit 1; }

# ─── 1. Update system ──────────────────────────────────────────────────────────
log "Updating system packages..."
apt-get update -qq && apt-get upgrade -y -qq
ok "System updated"

# ─── 2. Install Docker ─────────────────────────────────────────────────────────
if ! command -v docker &>/dev/null; then
    log "Installing Docker..."
    curl -fsSL https://get.docker.com | bash
    systemctl enable docker
    systemctl start docker
    ok "Docker installed"
else
    ok "Docker already installed: $(docker --version)"
fi

# ─── 3. Install Docker Compose ─────────────────────────────────────────────────
if ! command -v docker-compose &>/dev/null && ! docker compose version &>/dev/null; then
    log "Installing Docker Compose plugin..."
    apt-get install -y docker-compose-plugin
    ok "Docker Compose installed"
else
    ok "Docker Compose already installed"
fi

# ─── 4. Install Git ────────────────────────────────────────────────────────────
if ! command -v git &>/dev/null; then
    log "Installing git..."
    apt-get install -y git
fi
ok "Git ready"

# ─── 5. Setup project directory ────────────────────────────────────────────────
PROJECT_DIR="/opt/ai-trading"
log "Setting up project at $PROJECT_DIR ..."
mkdir -p $PROJECT_DIR
cd $PROJECT_DIR

echo ""
warn "══════════════════════════════════════════════"
warn " NEXT STEP: Copy your project files here"
warn "  From your PC run:"
warn "  scp -r . root@YOUR_SERVER_IP:$PROJECT_DIR/"
warn "══════════════════════════════════════════════"
echo ""

ok "Deploy script finished — now upload your files and run: start.sh"
