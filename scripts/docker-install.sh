#!/usr/bin/env bash
# scripts/docker-install.sh
# Contrust Docker installer — interactive setup wizard
# -------------------------------------------------------
# Usage:
#   bash scripts/docker-install.sh              # interactive (default)
#   bash scripts/docker-install.sh --non-interactive  # CI/CD, all defaults
# -------------------------------------------------------
set -euo pipefail

# ─── Colour helpers ──────────────────────────────────────────────────────────
RED='\033[0;31m'; YELLOW='\033[1;33m'; GREEN='\033[0;32m'
CYAN='\033[0;36m'; BOLD='\033[1m'; RESET='\033[0m'
info()    { echo -e "${CYAN}ℹ  ${RESET}$*"; }
success() { echo -e "${GREEN}✔  ${RESET}$*"; }
warn()    { echo -e "${YELLOW}⚠  ${RESET}$*"; }
error()   { echo -e "${RED}✖  ${RESET}$*" >&2; }
section() { echo -e "\n${BOLD}── $* $(printf '─%.0s' {1..40})${RESET}"; }

# ─── Portability ─────────────────────────────────────────────────────────────
# This script must run on macOS's stock /bin/bash 3.2 and on Git Bash (Windows),
# not only on Linux bash 5: no ${var,,} / ${var^^} expansions, no Linux-only tools.
lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }
upper() { printf '%s' "$1" | tr '[:lower:]' '[:upper:]'; }
# Git Bash rewrites arguments that look like POSIX paths before docker.exe sees them.
case "$(uname -s)" in MINGW*|MSYS*|CYGWIN*) export MSYS_NO_PATHCONV=1 ;; esac

# ─── Non-interactive flag ────────────────────────────────────────────────────
NON_INTERACTIVE=false
for arg in "$@"; do
  [[ "$arg" == "--non-interactive" ]] && NON_INTERACTIVE=true
done
$NON_INTERACTIVE && info "Non-interactive mode: all optional steps will use safe defaults."

# ─── Helper: generate a random hex secret ────────────────────────────────────
gen_secret() { openssl rand -hex "${1:-20}"; }

# ─── Helper: append a non-empty env var line to the override builder ─────────
# Usage: env_line VAR_NAME "value"   → echoes '  VAR_NAME: "value"' if non-empty
# The value is escaped for a YAML double-quoted string, and "$" is doubled so Docker
# Compose does not treat part of a password as a variable to interpolate.
env_line() {
  local key="$1" val="$2"
  [[ -z "$val" ]] && return
  val=$(printf '%s' "$val" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\$/$$/g')
  printf '  %s: "%s"\n' "$key" "$val"
}

# ─── Helper: read a value back from the override an earlier run wrote ────────
# Usage: previous_value VAR_NAME   → the first 'VAR_NAME: "value"' in the override
OVERRIDE_FILE="docker-compose.override.yml"
previous_value() {
  [[ -f "$OVERRIDE_FILE" ]] || return 0
  sed -n "s/^ *$1: \"\(.*\)\"\$/\1/p" "$OVERRIDE_FILE" | head -n 1
}

# ─── Helper: has the bundled MySQL already initialized its datadir? ──────────
DB_DATA_DIR="docker/database/mysql"
db_initialized() {
  [[ -e "$DB_DATA_DIR" ]] || return 1
  # MySQL takes ownership of the datadir when it initializes it, so a non-root user
  # usually cannot list it at all; that alone says it has been initialized.
  [[ -r "$DB_DATA_DIR" && -x "$DB_DATA_DIR" ]] || return 0
  [[ -n "$(ls -A "$DB_DATA_DIR" 2>/dev/null)" ]]
}

# ─── Helper: prompt until the answer is a bare domain name ───────────────────
# Usage: ask_domain "Prompt"   → the answer is left in DOMAIN_ANSWER
ask_domain() {
  local input re='^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$'
  while true; do
    read -rp "$1: " input
    input="$(lower "$input")"
    input="${input#http://}"; input="${input#https://}"; input="${input%%/*}"
    if [[ "$input" =~ $re ]]; then
      DOMAIN_ANSWER="$input"
      return
    fi
    warn "Enter a domain name such as app.example.com, without a scheme, port or path."
    warn "An IP address will not work: certificates are only issued for domain names."
  done
}

# ─── Helper: is Docker Compose at least 2.24.4? (docker-compose.prod.yml uses !reset)
compose_supports_reset() {
  local v maj min pat
  v="$(docker compose version --short 2>/dev/null || true)"
  v="${v#v}"
  maj="${v%%.*}"; v="${v#*.}"
  min="${v%%.*}"; v="${v#*.}"
  pat="${v%%[!0-9]*}"
  maj="${maj%%[!0-9]*}"; min="${min%%[!0-9]*}"
  maj="${maj:-0}"; min="${min:-0}"; pat="${pat:-0}"
  (( maj > 2 )) && return 0
  (( maj == 2 && min > 24 )) && return 0
  (( maj == 2 && min == 24 && pat >= 4 )) && return 0
  return 1
}

echo
echo -e "${BOLD}🚀  Contrust Installation Wizard${RESET}"
echo

###############################################################################
# STEP 0 — Pre-flight checks
###############################################################################
section "Pre-flight Checks"

# Required tools
for tool in docker git openssl; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    error "$tool is required but not found. Install it and retry."
    exit 1
  fi
  success "$tool found"
done

# Docker Compose v2
if ! docker compose version >/dev/null 2>&1; then
  error "'docker compose' (v2) is required. Please upgrade Docker Desktop or install the Compose plugin."
  exit 1
fi
success "Docker Compose v2 found"

# Port availability (warn only — do not block). Each OS ships a different tool:
# ss on Linux, lsof on macOS, netstat -an on Windows (Git Bash) and the BSDs.
# Returns 0 = in use, 1 = free, 2 = could not check.
port_in_use() {
  local port="$1"
  if command -v ss >/dev/null 2>&1; then
    ss -ltn 2>/dev/null | grep -Eq "[.:]${port}[[:space:]]"
  elif command -v lsof >/dev/null 2>&1; then
    lsof -nP -iTCP:"${port}" -sTCP:LISTEN -t >/dev/null 2>&1
  elif command -v netstat >/dev/null 2>&1; then
    netstat -an 2>/dev/null | grep -Eq "[.:]${port}[[:space:]].*LISTEN"
  else
    return 2
  fi
}

success "Pre-flight checks complete"

###############################################################################
# STEP 1 — Core parameters
###############################################################################
section "Core Configuration"

CONSOLE_DOMAIN=""; API_DOMAIN=""; ACME_EMAIL=""
if $NON_INTERACTIVE; then
  HOST="localhost"
  ENVIRONMENT="development"
  APP_NAME="Contrust"
else
  echo "  development: plain HTTP on ports 4200 (console), 8000 (API) and 38000 (sockets)."
  echo "  production:  HTTPS on your own domains, with free, auto-renewed Let's Encrypt"
  echo "               certificates. Only ports 80 and 443 are opened to the internet."
  while true; do
    read -rp "Environment (development / production) [development]: " ENV_INPUT
    ENV_INPUT=$(echo "$ENV_INPUT" | tr '[:upper:]' '[:lower:]')
    case "$ENV_INPUT" in
      ""|d|dev|development) ENVIRONMENT=development; break ;;
      p|prod|production)    ENVIRONMENT=production;  break ;;
      *) warn "Please type either 'development' or 'production'." ;;
    esac
  done

  if [[ "$ENVIRONMENT" == "production" ]]; then
    info "Production needs two domain names (e.g. app.example.com and api.example.com)"
    info "whose DNS A/AAAA records already point at this server."
    ask_domain "Console domain (e.g. app.example.com)"
    CONSOLE_DOMAIN="$DOMAIN_ANSWER"
    while true; do
      ask_domain "API domain (e.g. api.example.com)"
      API_DOMAIN="$DOMAIN_ANSWER"
      [[ "$API_DOMAIN" != "$CONSOLE_DOMAIN" ]] && break
      warn "The API needs a domain of its own, different from the console's."
    done
    while true; do
      read -rp "Email for Let's Encrypt certificate notices: " ACME_EMAIL
      case "$ACME_EMAIL" in
        *[[:space:]]*) ;;
        ?*@?*.?*) break ;;
      esac
      warn "Please enter a valid email address."
    done
    HOST="$API_DOMAIN"
  else
    read -rp "Host or IP address to bind to [localhost]: " HOST_INPUT
    HOST="${HOST_INPUT:-localhost}"
  fi

  read -rp "Application name [Contrust]: " APP_NAME_INPUT
  APP_NAME="${APP_NAME_INPUT:-Contrust}"
fi

# Derive scheme flags
APP_DEBUG=true
[[ "$ENVIRONMENT" == "production" ]] && APP_DEBUG=false

# Public URLs. Production is served by Caddy on 443 (docker-compose.prod.yml);
# development talks to the published container ports directly.
if [[ "$ENVIRONMENT" == "production" ]]; then
  API_URL="https://${API_DOMAIN}"
  CONSOLE_URL="https://${CONSOLE_DOMAIN}"
  SC_HOST="$API_DOMAIN"; SC_PORT="443"; SC_SECURE=true
else
  API_URL="http://${HOST}:8000"
  CONSOLE_URL="http://${HOST}:4200"
  SC_HOST="$HOST"; SC_PORT="38000"; SC_SECURE=false
fi

# Detect localhost
IS_LOCALHOST=false
[[ "$HOST" == "localhost" || "$HOST" == "0.0.0.0" || "$HOST" == "127.0.0.1" ]] && IS_LOCALHOST=true

if [[ "$ENVIRONMENT" == "production" ]]; then
  info "Console: $CONSOLE_URL  |  API: $API_URL  |  App name: $APP_NAME"
else
  info "Host: $HOST  |  Environment: $ENVIRONMENT  |  App name: $APP_NAME"
fi

# Production pre-flight: Compose version, DNS, and the ports Caddy needs.
if [[ "$ENVIRONMENT" == "production" ]]; then
  if ! compose_supports_reset; then
    error "Production mode needs Docker Compose 2.24.4 or newer (found: $(docker compose version --short 2>/dev/null || echo unknown))."
    error "Upgrade Docker (https://docs.docker.com/engine/install/) and re-run."
    exit 1
  fi
  if command -v getent >/dev/null 2>&1; then
    for domain in "$CONSOLE_DOMAIN" "$API_DOMAIN"; do
      if getent hosts "$domain" >/dev/null 2>&1; then
        success "$domain resolves"
      else
        warn "$domain does not resolve yet. Certificates are issued only once its DNS record points at this server."
      fi
    done
  fi
  PORT_CHECKS="80:HTTP 443:HTTPS"
else
  PORT_CHECKS="8000:API 4200:Console 3306:MySQL 38000:SocketCluster"
fi

# Port availability (warn only — do not block).
for port_label in $PORT_CHECKS; do
  port="${port_label%%:*}"
  label="${port_label##*:}"
  if port_in_use "$port"; then
    warn "Port ${port} (${label}) is already in use — this may cause a conflict."
  elif [[ $? -eq 2 ]]; then
    warn "Could not check whether port ${port} (${label}) is free (no ss, lsof or netstat found)."
  else
    success "Port ${port} (${label}) is free"
  fi
done

###############################################################################
# STEP 2 — Locate project root
###############################################################################
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$( cd "$SCRIPT_DIR/.." && pwd )"
cd "$PROJECT_ROOT"

###############################################################################
# STEP 3 — Database configuration
###############################################################################
section "Database Configuration"

DB_MODE="internal"   # default
DB_REUSED=false

if ! $NON_INTERACTIVE; then
  echo "  1) Bundled Docker MySQL  (recommended for development)"
  echo "  2) External MySQL server (e.g. AWS RDS, PlanetScale)"
  read -rp "Choose [1]: " DB_CHOICE_INPUT
  [[ "${DB_CHOICE_INPUT:-1}" == "2" ]] && DB_MODE="external"
fi

if [[ "$DB_MODE" == "external" ]]; then
  read -rp  "  Database host [127.0.0.1]: "  DB_HOST_INPUT;  DB_HOST="${DB_HOST_INPUT:-127.0.0.1}"
  read -rp  "  Database port [3306]: "        DB_PORT_INPUT;  DB_PORT="${DB_PORT_INPUT:-3306}"
  read -rp  "  Database name [fleetbase]: "   DB_NAME_INPUT;  DB_NAME="${DB_NAME_INPUT:-fleetbase}"
  read -rp  "  Database username: "           DB_USER
  read -srp "  Database password: "           DB_PASS; echo
  # URL-encode the password (basic: replace @ and / which are most problematic)
  # Windows installs usually expose `python`, not `python3`; fall back to the raw value.
  PY_QUOTE="import urllib.parse,sys; print(urllib.parse.quote(sys.argv[1],safe=''))"
  DB_PASS_ENC=$(python3 -c "$PY_QUOTE" "$DB_PASS" 2>/dev/null || python -c "$PY_QUOTE" "$DB_PASS" 2>/dev/null || echo "$DB_PASS")
  DATABASE_URL="mysql://${DB_USER}:${DB_PASS_ENC}@${DB_HOST}:${DB_PORT}/${DB_NAME}"
  DB_ROOT_PASSWORD=""
  DB_USERNAME="$DB_USER"
  DB_PASSWORD="$DB_PASS"
  DB_DATABASE="$DB_NAME"
  success "External database configured"
else
  # MySQL reads MYSQL_ROOT_PASSWORD / MYSQL_PASSWORD only when it initializes an empty
  # datadir. Once docker/database/mysql holds a database, new credentials would never
  # reach it and the API could no longer log in, so a re-run keeps the ones it has.
  if db_initialized; then
    DB_ROOT_PASSWORD="$(previous_value MYSQL_ROOT_PASSWORD)"
    DB_PASSWORD="$(previous_value MYSQL_PASSWORD)"
    DB_USERNAME="$(previous_value MYSQL_USER)"
    DB_DATABASE="$(previous_value MYSQL_DATABASE)"
    if [[ -z "$DB_ROOT_PASSWORD" || -z "$DB_PASSWORD" || -z "$DB_USERNAME" || -z "$DB_DATABASE" ]]; then
      error "$DB_DATA_DIR already holds a database, but $OVERRIDE_FILE does not have the credentials it was created with."
      error "Restore that install's $OVERRIDE_FILE (or one of its .bak copies) and re-run, or start over"
      error "with an EMPTY database by deleting the old one:  sudo rm -rf $DB_DATA_DIR"
      exit 1
    fi
    DB_REUSED=true
    success "Existing bundled database found — keeping its credentials"
  else
    DB_ROOT_PASSWORD="$(gen_secret 20)"
    DB_PASSWORD="$(gen_secret 20)"
    DB_USERNAME="fleetbase"
    DB_DATABASE="fleetbase"
    success "Secure database credentials auto-generated"
  fi
  DATABASE_URL="mysql://${DB_USERNAME}:${DB_PASSWORD}@database/${DB_DATABASE}"
fi

###############################################################################
# STEP 4 — Mail configuration
###############################################################################
section "Mail Configuration"

MAIL_MAILER="log"
MAIL_HOST=""; MAIL_PORT=""; MAIL_USERNAME=""; MAIL_PASSWORD=""
# Non-empty on purpose. env_line() skips empty values, so an empty default means the
# key never reaches docker-compose.override.yml — and api/.env.example ships the literal
# string MAIL_FROM_ADDRESS=null, which then OVERRIDES config/mail.php's default rather
# than falling back to it. Every non-interactive install ended up unable to send mail:
#
#   Email "null" does not comply with addr-spec of RFC 2822.
#
# which surfaces as a 400 on the verification-code endpoints and a 500 on any notification
# send. This value matches config/mail.php's own default, so the result is the same as if
# the key were genuinely unset.
MAIL_FROM_ADDRESS="hello@example.com"; MAIL_FROM_NAME="$APP_NAME"
MAILGUN_DOMAIN=""; MAILGUN_SECRET=""
POSTMARK_TOKEN=""; SENDGRID_API_KEY=""; RESEND_KEY=""

CONFIG_MAIL=false
if ! $NON_INTERACTIVE; then
  read -rp "Configure a mail server? Required for password resets & notifications (y/N): " MAIL_YN
  case "$(lower "$MAIL_YN")" in y|yes) CONFIG_MAIL=true ;; esac
fi

if $CONFIG_MAIL; then
  echo "  Mail drivers: 1) SMTP  2) Mailgun  3) Postmark  4) SendGrid  5) Resend  6) AWS SES  7) Log only"
  read -rp "  Choose driver [1]: " MAIL_DRIVER_INPUT
  case "${MAIL_DRIVER_INPUT:-1}" in
    2) MAIL_MAILER="mailgun" ;;
    3) MAIL_MAILER="postmark" ;;
    4) MAIL_MAILER="sendgrid" ;;
    5) MAIL_MAILER="resend" ;;
    6) MAIL_MAILER="ses" ;;
    7) MAIL_MAILER="log" ;;
    *) MAIL_MAILER="smtp" ;;
  esac

  DEFAULT_FROM="hello@$( $IS_LOCALHOST && echo 'example.com' || echo "$HOST" )"
  read -rp  "  From address [$DEFAULT_FROM]: " MAIL_FROM_INPUT
  MAIL_FROM_ADDRESS="${MAIL_FROM_INPUT:-$DEFAULT_FROM}"
  read -rp  "  From name [$APP_NAME]: " MAIL_FROM_NAME_INPUT
  MAIL_FROM_NAME="${MAIL_FROM_NAME_INPUT:-$APP_NAME}"

  case "$MAIL_MAILER" in
    smtp)
      read -rp  "  SMTP host [smtp.mailgun.org]: " MAIL_HOST_INPUT; MAIL_HOST="${MAIL_HOST_INPUT:-smtp.mailgun.org}"
      read -rp  "  SMTP port [587]: "               MAIL_PORT_INPUT; MAIL_PORT="${MAIL_PORT_INPUT:-587}"
      read -rp  "  SMTP username: "                 MAIL_USERNAME
      read -srp "  SMTP password: "                 MAIL_PASSWORD; echo
      ;;
    mailgun)
      read -rp  "  Mailgun domain: "      MAILGUN_DOMAIN
      read -srp "  Mailgun API secret: "  MAILGUN_SECRET; echo
      ;;
    postmark)
      read -srp "  Postmark server token: " POSTMARK_TOKEN; echo
      ;;
    sendgrid)
      read -srp "  SendGrid API key: " SENDGRID_API_KEY; echo
      ;;
    resend)
      read -srp "  Resend API key: " RESEND_KEY; echo
      ;;
    ses)
      info "AWS SES will use the AWS credentials configured in the Storage step."
      ;;
  esac
  success "Mail driver set to: $MAIL_MAILER"
else
  info "Skipped — emails will be written to the application log."
fi

###############################################################################
# STEP 5 — File storage
###############################################################################
section "File Storage Configuration"

FILESYSTEM_DRIVER="public"
AWS_ACCESS_KEY_ID=""; AWS_SECRET_ACCESS_KEY=""; AWS_DEFAULT_REGION=""
AWS_BUCKET=""; AWS_URL=""; AWS_USE_PATH_STYLE_ENDPOINT=""
GOOGLE_CLOUD_PROJECT_ID=""; GOOGLE_CLOUD_STORAGE_BUCKET=""; GOOGLE_CLOUD_KEY_FILE=""

if ! $NON_INTERACTIVE; then
  echo "  Storage drivers: 1) Local disk (dev only)  2) AWS S3  3) Google Cloud Storage"
  read -rp "  Choose driver [1]: " STORAGE_INPUT
  case "${STORAGE_INPUT:-1}" in
    2) FILESYSTEM_DRIVER="s3" ;;
    3) FILESYSTEM_DRIVER="gcs" ;;
    *) FILESYSTEM_DRIVER="public" ;;
  esac
fi

if [[ "$FILESYSTEM_DRIVER" == "s3" ]]; then
  read -rp  "  AWS Access Key ID: "                                     AWS_ACCESS_KEY_ID
  read -srp "  AWS Secret Access Key: "                                  AWS_SECRET_ACCESS_KEY; echo
  read -rp  "  AWS Region [us-east-1]: "                                 AWS_REGION_INPUT; AWS_DEFAULT_REGION="${AWS_REGION_INPUT:-us-east-1}"
  read -rp  "  S3 Bucket name: "                                         AWS_BUCKET
  read -rp  "  S3 Public URL (leave blank for default): "                AWS_URL
  read -rp  "  Use path-style endpoint? (for MinIO/non-AWS S3) (y/N): " PATH_STYLE_INPUT
  case "$(lower "$PATH_STYLE_INPUT")" in y|yes) AWS_USE_PATH_STYLE_ENDPOINT="true" ;; esac
  success "S3 storage configured"
elif [[ "$FILESYSTEM_DRIVER" == "gcs" ]]; then
  read -rp "  GCS Project ID: "      GOOGLE_CLOUD_PROJECT_ID
  read -rp "  GCS Bucket name: "     GOOGLE_CLOUD_STORAGE_BUCKET
  read -rp "  Path to GCS key file (JSON): " GOOGLE_CLOUD_KEY_FILE
  success "Google Cloud Storage configured"
else
  info "Local disk selected — suitable for development only."
fi

###############################################################################
# STEP 6 — Security & CORS
###############################################################################
section "Security & CORS Configuration"

# Derive SESSION_DOMAIN
SESSION_DOMAIN="$( $IS_LOCALHOST && echo 'localhost' || echo "$HOST" )"

FRONTEND_HOSTS=""
if ! $NON_INTERACTIVE; then
  read -rp "Additional frontend hosts for CORS (comma-separated, leave blank for none): " FRONTEND_HOSTS
fi

# Derive SOCKETCLUSTER_OPTIONS origins.
#
# SocketCluster matches each connection's Origin as "hostname:port", and the list has
# to be a JSON array: given a plain string it matches by substring, so "e.com:*"
# would also admit "console.example.com". "null:*" admits connections that send no
# Origin header at all, which is how the API itself publishes every real-time event
# (and how mobile apps connect). Leaving it out silently drops all live updates.
if [[ "$ENVIRONMENT" == "production" ]]; then
  SOCKET_HOSTS="$CONSOLE_DOMAIN $API_DOMAIN"
elif $IS_LOCALHOST; then
  SOCKET_HOSTS="localhost 127.0.0.1"
else
  SOCKET_HOSTS="$HOST"
fi
for frontend_host in $(printf '%s' "$FRONTEND_HOSTS" | tr ',' ' '); do
  frontend_host="${frontend_host#*://}"; frontend_host="${frontend_host%%/*}"; frontend_host="${frontend_host%%:*}"
  [[ -n "$frontend_host" ]] && SOCKET_HOSTS="$SOCKET_HOSTS $(lower "$frontend_host")"
done
SOCKET_ORIGINS=""
for socket_host in $SOCKET_HOSTS; do
  SOCKET_ORIGINS="${SOCKET_ORIGINS}\"${socket_host}:*\","
done
SOCKET_ORIGINS="${SOCKET_ORIGINS}\"null:*\""
SOCKETCLUSTER_OPTIONS="{\"origins\":[${SOCKET_ORIGINS}]}"

success "SESSION_DOMAIN set to: $SESSION_DOMAIN"
success "WebSocket origins restricted to: $(printf '%s' "$SOCKET_ORIGINS" | tr -d '"')"

###############################################################################
# STEP 7 — Optional third-party API keys
###############################################################################
section "Optional Third-Party Services"

IPINFO_API_KEY=""; GOOGLE_MAPS_API_KEY=""; GOOGLE_MAPS_LOCALE="us"
TWILIO_SID=""; TWILIO_TOKEN=""; TWILIO_FROM=""

CONFIG_3P=false
if ! $NON_INTERACTIVE; then
  read -rp "Configure optional third-party API keys now? (Maps, Geolocation, SMS) (y/N): " TP_YN
  case "$(lower "$TP_YN")" in y|yes) CONFIG_3P=true ;; esac
fi

if $CONFIG_3P; then
  read -rp  "  IPInfo API key (geolocation, leave blank to skip): "  IPINFO_API_KEY
  read -rp  "  Google Maps API key (leave blank to skip): "          GOOGLE_MAPS_API_KEY
  read -rp  "  Google Maps locale [us]: "                            GM_LOCALE_INPUT; GOOGLE_MAPS_LOCALE="${GM_LOCALE_INPUT:-us}"
  read -rp  "  Twilio Account SID (SMS, leave blank to skip): "      TWILIO_SID
  read -srp "  Twilio Auth Token: "                                   TWILIO_TOKEN; echo
  read -rp  "  Twilio From phone number: "                           TWILIO_FROM
  success "Third-party services configured"
else
  info "Skipped — these can be added later via docker-compose.override.yml"
fi

###############################################################################
# STEP 8 — Generate APP_KEY
###############################################################################
section "Generating Application Key"
# A new key on a re-run would make everything encrypted with the old one (two-factor
# secrets, stored OAuth credentials) unreadable, so an existing key is kept.
APP_KEY="$(previous_value APP_KEY)"
if [[ -n "$APP_KEY" ]]; then
  success "Existing APP_KEY kept"
else
  APP_KEY="base64:$(openssl rand -base64 32 | tr -d '\n')"
  success "APP_KEY generated"
fi

###############################################################################
# STEP 9 — Write docker-compose.override.yml
###############################################################################
section "Writing docker-compose.override.yml"

# Back up any existing override
if [[ -f "$OVERRIDE_FILE" ]]; then
  BACKUP="${OVERRIDE_FILE}.bak.$(date +%Y%m%d%H%M%S)"
  cp "$OVERRIDE_FILE" "$BACKUP"
  info "Existing override backed up to $BACKUP"
fi

# Build the file using a temp file for atomicity
OVERRIDE_TMP="${OVERRIDE_FILE}.tmp.$$"

{
  # One environment for the API, the queue worker and the scheduler. They run the same
  # code: given only the API's settings, the workers fell back to docker-compose.yml's
  # passwordless root login and no APP_KEY, and every queued job and scheduled task
  # failed against the password-protected database.
  cat <<YAML_HEADER
# Written by scripts/docker-install.sh. Re-running the installer keeps this install's
# APP_KEY and database credentials, and backs this file up before replacing it.
x-api-environment: &api-environment
YAML_HEADER

  env_line "APP_KEY"           "$APP_KEY"
  env_line "APP_NAME"          "$APP_NAME"
  env_line "APP_URL"           "$API_URL"
  env_line "CONSOLE_HOST"      "$CONSOLE_URL"
  env_line "ENVIRONMENT"       "$ENVIRONMENT"
  env_line "APP_DEBUG"         "$APP_DEBUG"
  env_line "DATABASE_URL"      "$DATABASE_URL"
  env_line "SESSION_DOMAIN"    "$SESSION_DOMAIN"
  [[ "$ENVIRONMENT" == "production" ]] && env_line "SESSION_SECURE_COOKIE" "true"
  env_line "FRONTEND_HOSTS"    "$FRONTEND_HOSTS"
  # Mail
  env_line "MAIL_MAILER"       "$MAIL_MAILER"
  env_line "MAIL_HOST"         "$MAIL_HOST"
  env_line "MAIL_PORT"         "$MAIL_PORT"
  env_line "MAIL_USERNAME"     "$MAIL_USERNAME"
  env_line "MAIL_PASSWORD"     "$MAIL_PASSWORD"
  env_line "MAIL_FROM_ADDRESS" "$MAIL_FROM_ADDRESS"
  env_line "MAIL_FROM_NAME"    "$MAIL_FROM_NAME"
  env_line "MAILGUN_DOMAIN"    "$MAILGUN_DOMAIN"
  env_line "MAILGUN_SECRET"    "$MAILGUN_SECRET"
  env_line "POSTMARK_TOKEN"    "$POSTMARK_TOKEN"
  env_line "SENDGRID_API_KEY"  "$SENDGRID_API_KEY"
  env_line "RESEND_KEY"        "$RESEND_KEY"
  # Storage
  [[ "$FILESYSTEM_DRIVER" != "public" ]] && env_line "FILESYSTEM_DRIVER" "$FILESYSTEM_DRIVER"
  env_line "AWS_ACCESS_KEY_ID"           "$AWS_ACCESS_KEY_ID"
  env_line "AWS_SECRET_ACCESS_KEY"       "$AWS_SECRET_ACCESS_KEY"
  env_line "AWS_DEFAULT_REGION"          "$AWS_DEFAULT_REGION"
  env_line "AWS_BUCKET"                  "$AWS_BUCKET"
  env_line "AWS_URL"                     "$AWS_URL"
  env_line "AWS_USE_PATH_STYLE_ENDPOINT" "$AWS_USE_PATH_STYLE_ENDPOINT"
  env_line "GOOGLE_CLOUD_PROJECT_ID"     "$GOOGLE_CLOUD_PROJECT_ID"
  env_line "GOOGLE_CLOUD_STORAGE_BUCKET" "$GOOGLE_CLOUD_STORAGE_BUCKET"
  env_line "GOOGLE_CLOUD_KEY_FILE"       "$GOOGLE_CLOUD_KEY_FILE"
  # Third-party
  env_line "IPINFO_API_KEY"      "$IPINFO_API_KEY"
  # Written even when empty. The published API images resolve an unset key to null,
  # and Fleet-Ops' map settings endpoint (/int/v1/fleet-ops/settings/map) then fails
  # with a 500 on every install without Google Maps. An empty string is treated as
  # "no key" everywhere, and a key saved in the console (Admin → Services) still
  # takes effect. Left out when api/.env sets the key, so that value is not shadowed.
  if [[ -n "$GOOGLE_MAPS_API_KEY" ]]; then
    env_line "GOOGLE_MAPS_API_KEY" "$GOOGLE_MAPS_API_KEY"
  elif ! grep -q '^GOOGLE_MAPS_API_KEY=' api/.env 2>/dev/null; then
    printf '  GOOGLE_MAPS_API_KEY: ""\n'
  fi
  env_line "GOOGLE_MAPS_LOCALE"  "$GOOGLE_MAPS_LOCALE"
  env_line "TWILIO_SID"          "$TWILIO_SID"
  env_line "TWILIO_TOKEN"        "$TWILIO_TOKEN"
  env_line "TWILIO_FROM"         "$TWILIO_FROM"

  cat <<YAML_SOCKET

services:
  application:
    environment: *api-environment

  queue:
    environment: *api-environment

  scheduler:
    environment: *api-environment

  socket:
    environment:
      SOCKETCLUSTER_OPTIONS: '${SOCKETCLUSTER_OPTIONS}'
YAML_SOCKET

  # Add database service block only when using the bundled container
  if [[ "$DB_MODE" == "internal" ]]; then
    cat <<YAML_DB

  database:
    environment:
      MYSQL_ROOT_PASSWORD: "${DB_ROOT_PASSWORD}"
      MYSQL_DATABASE: "${DB_DATABASE}"
      MYSQL_USER: "${DB_USERNAME}"
      MYSQL_PASSWORD: "${DB_PASSWORD}"
      MYSQL_ALLOW_EMPTY_PASSWORD: "no"
YAML_DB
  fi
} > "$OVERRIDE_TMP"

mv -f "$OVERRIDE_TMP" "$OVERRIDE_FILE"
success "$OVERRIDE_FILE written"

###############################################################################
# STEP 10 — Write console configuration files
###############################################################################
section "Updating Console Configuration"

CONFIG_DIR="console"
mkdir -p "$CONFIG_DIR"

OSRM_HOST="https://router.project-osrm.org"

# Read by the console in the browser at boot (load-runtime-config), so it wins over
# the values the console was built with.
cat > "${CONFIG_DIR}/fleetbase.config.json.tmp" <<JSON
{
  "API_HOST": "${API_URL}",
  "SOCKETCLUSTER_HOST": "${SC_HOST}",
  "SOCKETCLUSTER_PORT": "${SC_PORT}",
  "SOCKETCLUSTER_SECURE": "${SC_SECURE}",
  "SOCKETCLUSTER_PATH": "/socketcluster/"
}
JSON
mv -f "${CONFIG_DIR}/fleetbase.config.json.tmp" "${CONFIG_DIR}/fleetbase.config.json"

# Build-time defaults for the environment the console is built for.
ENV_DIR="${CONFIG_DIR}/environments"
mkdir -p "$ENV_DIR"

if [[ "$ENVIRONMENT" == "production" ]]; then
  cat > "${ENV_DIR}/.env.production" <<ENV_PROD
API_HOST=${API_URL}
API_NAMESPACE=int/v1
API_SECURE=true
SOCKETCLUSTER_PATH=/socketcluster/
SOCKETCLUSTER_HOST=${SC_HOST}
SOCKETCLUSTER_SECURE=true
SOCKETCLUSTER_PORT=${SC_PORT}
OSRM_HOST=${OSRM_HOST}
ENV_PROD
else
  cat > "${ENV_DIR}/.env.development" <<ENV_DEV
API_HOST=${API_URL}
API_NAMESPACE=int/v1
SOCKETCLUSTER_PATH=/socketcluster/
SOCKETCLUSTER_HOST=${SC_HOST}
SOCKETCLUSTER_SECURE=false
SOCKETCLUSTER_PORT=${SC_PORT}
OSRM_HOST=${OSRM_HOST}
ENV_DEV
fi

success "Console configuration files updated"

###############################################################################
# STEP 10a — Select the Compose files (root .env)
###############################################################################
# Docker Compose reads .env next to docker-compose.yml on its own. In production it
# names docker-compose.prod.yml in COMPOSE_FILE, so every later `docker compose`
# command (up, pull, logs, exec) includes it without extra flags. Other lines in an
# existing .env are kept; switching back to development removes these again.
ROOT_ENV_FILE=".env"
ROOT_ENV_KEYS="COMPOSE_FILE|COMPOSE_PATH_SEPARATOR|FLEETBASE_VERSION|CONSOLE_DOMAIN|API_DOMAIN|ACME_EMAIL"
ROOT_ENV_TMP="${ROOT_ENV_FILE}.tmp.$$"
if [[ -f "$ROOT_ENV_FILE" ]]; then
  grep -Ev "^(${ROOT_ENV_KEYS})=|^# Written by scripts/docker-install.sh" "$ROOT_ENV_FILE" > "$ROOT_ENV_TMP" || true
else
  : > "$ROOT_ENV_TMP"
fi
if [[ "$ENVIRONMENT" == "production" ]]; then
  # The API image published for this checkout, so the API, queue and scheduler match
  # the console built from it.
  FLEETBASE_VERSION="$(sed -n 's/^ *"version": *"\([^"]*\)".*/\1/p' console/package.json | head -n 1)"
  if [[ -z "$FLEETBASE_VERSION" ]]; then
    rm -f "$ROOT_ENV_TMP"
    error "Could not read the Contrust version from console/package.json."
    exit 1
  fi
  cat >> "$ROOT_ENV_TMP" <<ROOT_ENV
# Written by scripts/docker-install.sh (production)
COMPOSE_PATH_SEPARATOR=:
COMPOSE_FILE=docker-compose.yml:docker-compose.override.yml:docker-compose.prod.yml
FLEETBASE_VERSION=v${FLEETBASE_VERSION}
CONSOLE_DOMAIN=${CONSOLE_DOMAIN}
API_DOMAIN=${API_DOMAIN}
ACME_EMAIL=${ACME_EMAIL}
ROOT_ENV
fi
if [[ -s "$ROOT_ENV_TMP" || -f "$ROOT_ENV_FILE" ]]; then
  mv -f "$ROOT_ENV_TMP" "$ROOT_ENV_FILE"
  success "$ROOT_ENV_FILE updated"
else
  rm -f "$ROOT_ENV_TMP"
fi

###############################################################################
# STEP 10b — Ensure api/.env exists
###############################################################################
# docker-compose.yml bind-mounts ./api/.env into the application container. When the
# file is missing Docker creates a *directory* at that path and Laravel cannot boot.
# Values set in docker-compose.override.yml take precedence over this file, so an
# empty file with a comment header is the correct default.
API_ENV_FILE="api/.env"
if [[ -d "$API_ENV_FILE" ]]; then
  error "$API_ENV_FILE is a directory (left behind by an earlier 'docker compose up' before the file existed)."
  error "Remove it and re-run the installer:  sudo rm -rf $API_ENV_FILE"
  exit 1
elif [[ ! -f "$API_ENV_FILE" ]]; then
  mkdir -p "$(dirname "$API_ENV_FILE")"
  cat > "$API_ENV_FILE" <<'ENV_API'
# Contrust API environment overrides.
# Runtime configuration comes from docker-compose.override.yml and takes precedence
# over this file; add per-host secrets or extra overrides here.
ENV_API
  success "$API_ENV_FILE created"
else
  success "$API_ENV_FILE already present"
fi

###############################################################################
# STEP 11 — Start containers
###############################################################################
section "Starting Contrust Containers"
echo "  This may take a few minutes on first run..."
if [[ "$ENVIRONMENT" == "production" ]]; then
  echo "  The production console build alone can take 10–20 minutes and 4–5 GB of memory."
fi
# --build: `up` alone reuses an existing image, so a re-run (after a `git pull`, or
# switching to production) would keep serving the console built the first time.
docker compose up -d --build

###############################################################################
# STEP 12 — Wait for database
###############################################################################
section "Waiting for Database"
DB_SERVICE="database"
DB_WAIT_TIMEOUT=90

DB_CONTAINER=$(docker compose ps -q "$DB_SERVICE" 2>/dev/null || true)
if [[ -z "$DB_CONTAINER" ]]; then
  error "Cannot find a running container for service \"$DB_SERVICE\". Check docker-compose.yml."
  exit 1
fi

# Prefer Docker HEALTHCHECK if defined, fall back to mysqladmin ping
if docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER" &>/dev/null; then
  SECONDS=0
  until [[ "$(docker inspect -f '{{.State.Health.Status}}' "$DB_CONTAINER")" == "healthy" ]]; do
    if (( SECONDS >= DB_WAIT_TIMEOUT )); then
      error "Timed out waiting for the database to become healthy."
      exit 1
    fi
    sleep 2
  done
  sleep 5  # brief grace period after healthy
else
  SECONDS=0
  until docker compose exec -T "$DB_SERVICE" sh -c "mysqladmin --silent --wait=1 -uroot -h127.0.0.1 ping" &>/dev/null; do
    if (( SECONDS >= DB_WAIT_TIMEOUT )); then
      error "Timed out waiting for the database to accept connections."
      exit 1
    fi
    sleep 2
  done
fi
success "Database is ready"

###############################################################################
# STEP 12b — Grant privileges for the bundled database
###############################################################################
# The bundled MySQL grants the app user (DB_USERNAME) privileges on the primary
# database only. deploy.sh also provisions a separate sandbox database
# (fleetbase_sandbox) via `php artisan sandbox:migrate`, which requires the
# CREATE privilege at the server level. Grant it here using the root credentials
# generated above so the limited app user can create/manage the sandbox DB.
if [[ "$DB_MODE" == "internal" ]]; then
  section "Granting Database Privileges"
  docker compose exec -T "$DB_SERVICE" \
    mysql -uroot -p"${DB_ROOT_PASSWORD}" \
    -e "GRANT ALL PRIVILEGES ON *.* TO '${DB_USERNAME}'@'%'; FLUSH PRIVILEGES;"
  success "Privileges granted to '${DB_USERNAME}'"
fi

###############################################################################
# STEP 13 — Run deploy script
###############################################################################
section "Running Deployment Script"
docker compose exec -T application bash -c "./deploy.sh"
docker compose up -d
success "Deployment complete"

###############################################################################
# STEP 14 — Post-install summary
###############################################################################
CONFIGURED_ITEMS=()
SKIPPED_ITEMS=()

[[ "$DB_MODE" == "external" ]] \
  && CONFIGURED_ITEMS+=("External Database") \
  || { $DB_REUSED \
    && CONFIGURED_ITEMS+=("Bundled MySQL (existing database and credentials kept)") \
    || CONFIGURED_ITEMS+=("Bundled MySQL (secure credentials auto-generated)"); }

$CONFIG_MAIL \
  && CONFIGURED_ITEMS+=("Mail (${MAIL_MAILER})") \
  || SKIPPED_ITEMS+=("Mail (using log driver — configure later)")

[[ "$FILESYSTEM_DRIVER" != "public" ]] \
  && CONFIGURED_ITEMS+=("File Storage ($(upper "$FILESYSTEM_DRIVER"))") \
  || SKIPPED_ITEMS+=("File storage (local disk — not suitable for production)")

CONFIGURED_ITEMS+=("WebSocket security (origins restricted to: ${SOCKET_HOSTS})")
[[ "$ENVIRONMENT" == "production" ]] \
  && CONFIGURED_ITEMS+=("HTTPS via Caddy + Let's Encrypt (only ports 80/443 are public)")

$CONFIG_3P \
  && CONFIGURED_ITEMS+=("Third-party APIs (Maps, Geolocation, SMS)") \
  || SKIPPED_ITEMS+=("Third-party APIs (Maps, Geolocation, SMS)")

echo
printf '%0.s═' {1..60}; echo
echo -e "  ${BOLD}🏁  Contrust Installation Complete${RESET}"
printf '%0.s═' {1..60}; echo
echo
echo "  📍  Endpoints"
printf "      API     → %s\n" "$API_URL"
printf "      Console → %s\n" "$CONSOLE_URL"
if [[ ${#CONFIGURED_ITEMS[@]} -gt 0 ]]; then
  echo
  echo "  ✔   Configured:"
  for item in "${CONFIGURED_ITEMS[@]}"; do echo "      • $item"; done
fi
if [[ ${#SKIPPED_ITEMS[@]} -gt 0 ]]; then
  echo
  echo "  ⚠   Skipped (defaults applied):"
  for item in "${SKIPPED_ITEMS[@]}"; do echo "      • $item"; done
fi
echo
echo "  🔐  Next Steps"
echo "      1. Open the Console URL in your browser."
echo "      2. Complete the onboarding wizard to create your"
echo "         initial organization and administrator account."
if [[ ${#SKIPPED_ITEMS[@]} -gt 0 ]]; then
  echo "      3. To configure skipped options, edit"
  echo "         docker-compose.override.yml and run:"
  echo "         docker compose up -d"
fi
echo
echo "  📄  Config saved to: docker-compose.override.yml"
printf '%0.s═' {1..60}; echo
echo
