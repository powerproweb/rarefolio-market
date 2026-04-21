#!/usr/bin/env bash
# =============================================================================
#  rf-deploy.sh  —  One-shot Rarefolio marketplace server setup
# =============================================================================
#  What this does (in order):
#     1.  Locate the marketplace folder on this server.
#     2.  Run   php db/migrate.php   as the cPanel user that owns the files.
#     3.  Ensure Node.js 20+ is installed (NodeSource repo if needed).
#     4.  cd sidecar ; npm ci ; npm run build
#     5.  Install / update a systemd unit:   rarefolio-sidecar.service
#     6.  Start the service and wait for   GET /health   on 127.0.0.1:4000.
#     7.  Point the marketplace .env at   http://127.0.0.1:4000
#     8.  Print a full summary and any "next manual steps".
#
#  DESIGN:
#     * Idempotent. Every step short-circuits cleanly if already done.
#     * No `set -e` — we handle per-step errors with clear messages and decide
#       whether to stop. Partial runs leave the box in a known state.
#     * Writes a full transcript to /tmp/rf-deploy-<timestamp>.log so if your
#       SSH session drops you can pick up by reading that file.
#     * Uses only POSIX-adjacent bash; no external deps beyond what a stock
#       cPanel/WHM install already has.
#
#  HOW TO RUN:
#     1. Put this file on the server (git pull, scp, File Manager upload, etc.).
#     2. Log in to the server and become root.
#     3. chmod +x /path/to/rf-deploy.sh
#     4. bash /path/to/rf-deploy.sh
#        (or ./rf-deploy.sh if executable bit is set)
#
#  ENV OVERRIDES (optional):
#     MARKETPLACE_ROOT=/custom/path    Skip auto-detection.
#     SIDECAR_PORT=4000                Port the sidecar listens on.
#     SKIP_NODE_INSTALL=1              Trust whatever node is already installed.
#
#  REQUIREMENTS:
#     * Run as root (dedicated BlueHost is fine).
#     * PHP 8.1+ already installed (cPanel default).
#     * curl, tar, basic GNU coreutils (all present by default).
# =============================================================================

set -uo pipefail

# ---------- log everything to stdout and a file --------------------------------
LOG_FILE="/tmp/rf-deploy-$(date +%Y%m%d-%H%M%S).log"
exec > >(tee -a "$LOG_FILE") 2>&1

# ---------- helpers ------------------------------------------------------------
C_OK='\033[0;32m'; C_FAIL='\033[0;31m'; C_INFO='\033[0;36m'; C_WARN='\033[0;33m'; C_RST='\033[0m'
log_ok()   { printf "${C_OK}  ok${C_RST}    %s\n" "$*"; }
log_fail() { printf "${C_FAIL}  FAIL${C_RST}  %s\n" "$*" >&2; exit 1; }
log_info() { printf "${C_INFO}  info${C_RST}  %s\n" "$*"; }
log_warn() { printf "${C_WARN}  warn${C_RST}  %s\n" "$*"; }
log_step() { printf "\n${C_INFO}===${C_RST} %s ${C_INFO}===${C_RST}\n" "$*"; }

SIDECAR_PORT="${SIDECAR_PORT:-4000}"

# ---------- step 0: preflight --------------------------------------------------
log_step "Preflight"
log_info "log file: $LOG_FILE"

if [[ $EUID -ne 0 ]]; then
    log_fail "this script must run as root (current user: $(whoami))"
fi
log_ok "running as root"

if ! command -v php >/dev/null 2>&1; then
    log_fail "php not found in PATH; install PHP 8.1+ first"
fi
log_ok "php: $(php -v 2>/dev/null | head -1)"

if ! command -v curl >/dev/null 2>&1; then
    log_fail "curl not found; install it first (dnf install -y curl)"
fi
log_ok "curl available"

# ---------- step 1: find the marketplace --------------------------------------
log_step "Locate marketplace"

MARKETPLACE_ROOT="${MARKETPLACE_ROOT:-}"
if [[ -z "$MARKETPLACE_ROOT" ]]; then
    CANDIDATE="$(find /home -maxdepth 6 -path '*/db/migrate.php' -type f 2>/dev/null | head -1)"
    MARKETPLACE_ROOT="${CANDIDATE%/db/migrate.php}"
fi

if [[ -z "$MARKETPLACE_ROOT" || ! -d "$MARKETPLACE_ROOT" ]]; then
    log_fail "could not auto-detect marketplace root. Re-run with:  MARKETPLACE_ROOT=/path/to/market bash $0"
fi
log_ok "marketplace root: $MARKETPLACE_ROOT"

[[ -f "$MARKETPLACE_ROOT/.env" ]] \
    || log_fail "missing $MARKETPLACE_ROOT/.env — upload the production env first"
log_ok "marketplace .env present"

[[ -f "$MARKETPLACE_ROOT/db/migrate.php" ]] \
    || log_fail "missing db/migrate.php under marketplace root"
log_ok "db/migrate.php present"

CPANEL_USER="$(stat -c '%U' "$MARKETPLACE_ROOT")"
if [[ -z "$CPANEL_USER" || "$CPANEL_USER" == "root" ]]; then
    log_warn "marketplace root is owned by '$CPANEL_USER' — unusual but proceeding"
fi
log_ok "files owned by: $CPANEL_USER"

# harden env file perms (owner read/write only)
chmod 600 "$MARKETPLACE_ROOT/.env" 2>/dev/null && log_ok "marketplace .env perms -> 600" || true

# ---------- step 2: run migrations --------------------------------------------
log_step "Database migrations"

# IMPORTANT: use -s /bin/bash so this works even on cPanel accounts where the
# user's default login shell is jailshell / nologin / "Shell access disabled".
# BlueHost disables shell by default for non-root cPanel users. Without -s,
# every command here would silently be swallowed by the locked shell and the
# script would claim "success" while doing nothing.
RUN_AS_USER="su - $CPANEL_USER -s /bin/bash -c"

MIGRATE_OUT="$($RUN_AS_USER "cd '$MARKETPLACE_ROOT' && php db/migrate.php" 2>&1)"
MIGRATE_RC=$?
echo "$MIGRATE_OUT"

# Defensive check: if the user's shell is STILL locked despite -s, bail loudly.
if echo "$MIGRATE_OUT" | grep -qi 'Shell access is not enabled'; then
    log_fail "cPanel account $CPANEL_USER has shell access disabled and -s /bin/bash did not bypass it. Enable shell for this user in WHM -> Manage Shell Access, or re-run with MARKETPLACE_USER=root (not recommended)."
fi

if [[ $MIGRATE_RC -ne 0 ]] || echo "$MIGRATE_OUT" | grep -qE '^FAIL|Fatal error|Uncaught'; then
    log_fail "migration runner exited with errors — see output above"
fi
APPLIED="$(echo "$MIGRATE_OUT" | grep -cE '^ok ' || true)"
SKIPPED="$(echo "$MIGRATE_OUT" | grep -cE '^skip ' || true)"
log_ok "migrations done ($APPLIED newly applied, $SKIPPED previously applied)"

# ---------- step 3: ensure Node 20+ -------------------------------------------
log_step "Node.js 20+"

NEED_INSTALL=0
if command -v node >/dev/null 2>&1; then
    NODE_VERSION="$(node --version 2>/dev/null || echo v0)"
    NODE_MAJOR="${NODE_VERSION#v}"; NODE_MAJOR="${NODE_MAJOR%%.*}"
    if [[ "$NODE_MAJOR" =~ ^[0-9]+$ ]] && [[ "$NODE_MAJOR" -ge 20 ]]; then
        log_ok "node already present: $NODE_VERSION"
    else
        log_warn "node present but version $NODE_VERSION < 20; will upgrade"
        NEED_INSTALL=1
    fi
else
    log_info "node not installed"
    NEED_INSTALL=1
fi

if [[ "${SKIP_NODE_INSTALL:-0}" == "1" ]]; then
    log_warn "SKIP_NODE_INSTALL=1 set; skipping node install even if out of date"
    NEED_INSTALL=0
fi

if [[ $NEED_INSTALL -eq 1 ]]; then
    if command -v dnf >/dev/null 2>&1; then PM=dnf
    elif command -v yum >/dev/null 2>&1; then PM=yum
    elif command -v apt-get >/dev/null 2>&1; then PM=apt
    else log_fail "no dnf/yum/apt found; unsupported distro"
    fi
    log_info "using package manager: $PM"

    if [[ "$PM" == "apt" ]]; then
        curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
            || log_fail "NodeSource setup (deb) failed"
        apt-get install -y nodejs \
            || log_fail "apt install nodejs failed"
    else
        curl -fsSL https://rpm.nodesource.com/setup_20.x | bash - \
            || log_fail "NodeSource setup (rpm) failed"
        "$PM" install -y nodejs \
            || log_fail "$PM install nodejs failed"
    fi
    log_ok "node installed: $(node --version)"
fi

NODE_BIN="$(command -v node)"
NPM_BIN="$(command -v npm)"
log_ok "node binary: $NODE_BIN"
log_ok "npm binary: $NPM_BIN"

# ---------- step 4: sidecar — deps + build ------------------------------------
log_step "Sidecar build (npm ci + tsc)"

SIDECAR_ROOT="$MARKETPLACE_ROOT/sidecar"
[[ -d "$SIDECAR_ROOT" ]] || log_fail "sidecar directory not found at $SIDECAR_ROOT"
log_ok "sidecar root: $SIDECAR_ROOT"

# Ensure a .env exists for the sidecar. Prefer .env.production if present.
# Note: file copies run as root then chown back to the cPanel user. Doing cp
# via the user's shell fails silently on shell-locked accounts even with -s.
if [[ ! -f "$SIDECAR_ROOT/.env" ]]; then
    if [[ -f "$SIDECAR_ROOT/.env.production" ]]; then
        cp "$SIDECAR_ROOT/.env.production" "$SIDECAR_ROOT/.env"
        chown "$CPANEL_USER:$CPANEL_USER" "$SIDECAR_ROOT/.env"
        log_ok "copied sidecar/.env.production -> sidecar/.env"
    elif [[ -f "$SIDECAR_ROOT/.env.example" ]]; then
        cp "$SIDECAR_ROOT/.env.example" "$SIDECAR_ROOT/.env"
        chown "$CPANEL_USER:$CPANEL_USER" "$SIDECAR_ROOT/.env"
        log_warn "copied sidecar/.env.example -> sidecar/.env (contains placeholders — sidecar will start but mint/policy endpoints will fail until this file is filled in)"
    else
        log_fail "no sidecar/.env, .env.production, or .env.example found"
    fi
fi
chmod 600 "$SIDECAR_ROOT/.env" 2>/dev/null && log_ok "sidecar .env perms -> 600" || true

if grep -q 'FILL_IN' "$SIDECAR_ROOT/.env" 2>/dev/null; then
    log_warn "sidecar/.env still contains <FILL_IN_...> placeholders — mint endpoints will not work until these are filled in (POLICY_MNEMONIC_FOUNDERS, PLATFORM_PAYOUT_ADDR, CREATOR_ROYALTY_ADDR, etc.)"
fi
if grep -qE '^POLICY_MNEMONIC(_FOUNDERS)?=word1 word2 word3' "$SIDECAR_ROOT/.env" 2>/dev/null; then
    log_warn "sidecar/.env has the .env.example placeholder mnemonic (word1 word2 word3 ...) — mint endpoints will fail until a real 24-word mnemonic is set."
fi

log_info "running npm ci (this is usually 1–3 minutes)"
NPM_CI_OUT="$($RUN_AS_USER "cd '$SIDECAR_ROOT' && npm ci" 2>&1)"
NPM_CI_RC=$?
echo "$NPM_CI_OUT" | tail -20
if echo "$NPM_CI_OUT" | grep -qi 'Shell access is not enabled'; then
    log_fail "cPanel shell is still blocking commands for $CPANEL_USER. Enable shell access in WHM -> Manage Shell Access (choose 'Normal Shell' or 'Jailed Shell'), then re-run this script."
fi
[[ $NPM_CI_RC -eq 0 ]] || log_fail "npm ci exited with code $NPM_CI_RC — see output above"
[[ -d "$SIDECAR_ROOT/node_modules" ]] \
    || log_fail "npm ci claimed success but $SIDECAR_ROOT/node_modules is missing — likely a silent shell-lockout"
log_ok "npm ci completed ($(ls "$SIDECAR_ROOT/node_modules" 2>/dev/null | wc -l) packages installed)"

log_info "running npm run build (tsc)"
NPM_BUILD_OUT="$($RUN_AS_USER "cd '$SIDECAR_ROOT' && npm run build" 2>&1)"
NPM_BUILD_RC=$?
echo "$NPM_BUILD_OUT" | tail -20
if echo "$NPM_BUILD_OUT" | grep -qi 'Shell access is not enabled'; then
    log_fail "cPanel shell is still blocking commands for $CPANEL_USER during build."
fi
[[ $NPM_BUILD_RC -eq 0 ]] || log_fail "npm run build exited with code $NPM_BUILD_RC — see output above"

[[ -f "$SIDECAR_ROOT/dist/index.js" ]] \
    || log_fail "expected $SIDECAR_ROOT/dist/index.js after build but it's missing"
log_ok "dist/index.js present"

# ---------- step 5: install systemd unit --------------------------------------
log_step "systemd unit"

UNIT_PATH="/etc/systemd/system/rarefolio-sidecar.service"
DESIRED_UNIT=$(cat <<EOF
[Unit]
Description=Rarefolio Cardano Sidecar
After=network.target

[Service]
Type=simple
User=$CPANEL_USER
WorkingDirectory=$SIDECAR_ROOT
EnvironmentFile=$SIDECAR_ROOT/.env
ExecStart=$NODE_BIN $SIDECAR_ROOT/dist/index.js
Restart=always
RestartSec=5
StandardOutput=append:/var/log/rarefolio-sidecar.log
StandardError=append:/var/log/rarefolio-sidecar.log

[Install]
WantedBy=multi-user.target
EOF
)

# Ensure log file exists with correct perms (systemd creates if missing, but be explicit)
touch /var/log/rarefolio-sidecar.log
chmod 644 /var/log/rarefolio-sidecar.log
chown root:root /var/log/rarefolio-sidecar.log

if [[ -f "$UNIT_PATH" ]] && [[ "$(cat "$UNIT_PATH")" == "$DESIRED_UNIT" ]]; then
    log_ok "systemd unit already up to date"
    UNIT_CHANGED=0
else
    printf '%s\n' "$DESIRED_UNIT" > "$UNIT_PATH"
    systemctl daemon-reload
    log_ok "systemd unit written: $UNIT_PATH"
    UNIT_CHANGED=1
fi

# ---------- step 6: start and health-check ------------------------------------
log_step "Start sidecar service"

systemctl enable rarefolio-sidecar >/dev/null 2>&1 || true
# Always (re)start so we pick up any .env / unit / code changes from this run
systemctl restart rarefolio-sidecar
log_ok "issued: systemctl restart rarefolio-sidecar"

# Wait up to 30s for the health endpoint
log_info "waiting for http://127.0.0.1:$SIDECAR_PORT/health ..."
HEALTH_OK=0
for attempt in $(seq 1 30); do
    if curl -fsS --max-time 3 "http://127.0.0.1:$SIDECAR_PORT/health" >/tmp/rf-sidecar-health.json 2>/dev/null; then
        HEALTH_OK=1
        break
    fi
    sleep 1
done

if [[ $HEALTH_OK -ne 1 ]]; then
    log_warn "health probe failed; recent service logs:"
    journalctl -u rarefolio-sidecar -n 40 --no-pager 2>/dev/null || \
        tail -40 /var/log/rarefolio-sidecar.log 2>/dev/null || true
    log_fail "sidecar did not become healthy within 30s"
fi

HEALTH_BODY="$(cat /tmp/rf-sidecar-health.json)"
log_ok "sidecar /health responded: $HEALTH_BODY"

if ! systemctl is-active --quiet rarefolio-sidecar; then
    log_fail "systemd says rarefolio-sidecar is not active; check 'systemctl status rarefolio-sidecar'"
fi
log_ok "systemd status: active"

# ---------- step 7: wire marketplace -> sidecar -------------------------------
log_step "Wire marketplace .env to local sidecar"

MP_ENV="$MARKETPLACE_ROOT/.env"
DESIRED_LINE="SIDECAR_BASE_URL=http://127.0.0.1:$SIDECAR_PORT"

if grep -qE "^SIDECAR_BASE_URL=" "$MP_ENV"; then
    CURRENT_LINE="$(grep -E "^SIDECAR_BASE_URL=" "$MP_ENV" | head -1)"
    if [[ "$CURRENT_LINE" == "$DESIRED_LINE" ]]; then
        log_ok "SIDECAR_BASE_URL already set to $DESIRED_LINE"
    else
        # In-place edit, preserving everything else
        sed -i "s|^SIDECAR_BASE_URL=.*|$DESIRED_LINE|" "$MP_ENV"
        log_ok "updated SIDECAR_BASE_URL (was: $CURRENT_LINE)"
    fi
else
    printf '\n%s\n' "$DESIRED_LINE" >> "$MP_ENV"
    log_ok "appended $DESIRED_LINE to marketplace .env"
fi

# ---------- step 8: final summary ---------------------------------------------
log_step "Summary"

echo ""
echo "  marketplace root           : $MARKETPLACE_ROOT"
echo "  marketplace .env           : $MP_ENV"
echo "  sidecar root               : $SIDECAR_ROOT"
echo "  sidecar .env               : $SIDECAR_ROOT/.env"
echo "  cPanel user                : $CPANEL_USER"
echo "  node binary                : $NODE_BIN ($(node --version))"
echo "  systemd unit               : $UNIT_PATH"
echo "  sidecar status             : $(systemctl is-active rarefolio-sidecar)"
echo "  sidecar port               : $SIDECAR_PORT"
echo "  health endpoint            : http://127.0.0.1:$SIDECAR_PORT/health"
echo "  sidecar log                : /var/log/rarefolio-sidecar.log"
echo "  deploy transcript          : $LOG_FILE"
echo "  marketplace SIDECAR line   : $(grep -E '^SIDECAR_BASE_URL=' "$MP_ENV" | head -1)"
echo ""

echo "Next steps (all manual):"
echo "  1. Fill in any FILL_IN placeholders in $SIDECAR_ROOT/.env"
echo "     (POLICY_MNEMONIC_FOUNDERS, SPLIT_MNEMONIC_FOUNDERS, PLATFORM_PAYOUT_ADDR, CREATOR_ROYALTY_ADDR)"
echo "     Then: systemctl restart rarefolio-sidecar"
echo ""
echo "  2. Fund the policy wallet once the mnemonic is set. Derive its address via:"
echo "     curl http://127.0.0.1:$SIDECAR_PORT/mint/policy-id"
echo ""
echo "  3. Optional — create a public subdomain for the sidecar (sidecar.rarefolio.io):"
echo "       a. cPanel -> Subdomains -> create sidecar.rarefolio.io"
echo "       b. cPanel -> SSL/TLS Status -> Run AutoSSL for that subdomain"
echo "       c. Add an Apache reverse-proxy vhost to 127.0.0.1:$SIDECAR_PORT"
echo ""
echo "  4. Useful operational commands:"
echo "       systemctl status  rarefolio-sidecar"
echo "       systemctl restart rarefolio-sidecar"
echo "       journalctl -u rarefolio-sidecar -f"
echo "       tail -f /var/log/rarefolio-sidecar.log"
echo ""

log_ok "rf-deploy.sh complete — all automated steps succeeded"
