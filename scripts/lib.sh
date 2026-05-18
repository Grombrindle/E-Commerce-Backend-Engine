#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
# lib.sh — Shared utilities for E-Commerce API test scripts
# ═══════════════════════════════════════════════════════════════════════
#
# Usage:
#   source "$(dirname "$0")/lib.sh"
#
# Configuration (override with environment variables):
#   API_BASE    — Base URL (default: http://localhost:8080/api/v1)
#   TIMEOUT     — curl timeout in seconds (default: 10)
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail

# ── Configuration ────────────────────────────────────────────────────
API_BASE="${API_BASE:-http://localhost:8080/api/v1}"
TIMEOUT="${TIMEOUT:-10}"
PASS_COUNT=0
FAIL_COUNT=0

# Derive project root from SCRIPT_DIR (set by each script before sourcing lib.sh)
PROJECT_ROOT="${SCRIPT_DIR%/scripts}"
[ "$PROJECT_ROOT" = "$SCRIPT_DIR" ] && PROJECT_ROOT="$SCRIPT_DIR/.."

# ── Colors ────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color

# ── Helpers ───────────────────────────────────────────────────────────

# Print a section header
header() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}  ${BOLD}$1${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# Print a step description
step() {
    echo -e "${YELLOW}  ▶${NC} $1"
}

# Print a success message
ok() {
    echo -e "    ${GREEN}✓${NC} $1"
    PASS_COUNT=$((PASS_COUNT + 1))
}

# Print a failure message
fail() {
    echo -e "    ${RED}✗${NC} $1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

# Print a warning (not pass/fail)
warn() {
    echo -e "    ${YELLOW}⚠${NC} $1"
}

# Print a sub-step
sub() {
    echo -e "    ${CYAN}→${NC} $1"
}

# Run a curl command, capturing HTTP status code and body
# Usage: api_call METHOD URL [DATA]
# Sets: API_STATUS, API_BODY, API_SUCCESS
api_call() {
    local method="$1"
    local url="${API_BASE}$2"
    local data="${3:-}"

    local headers=(-H "Content-Type: application/json" -H "Accept: application/json")

    if [ -n "${TOKEN:-}" ]; then
        headers+=(-H "Authorization: Bearer $TOKEN")
    fi

    local args=(-s -S -X "$method" "${headers[@]}" --connect-timeout "$TIMEOUT" -w "\n%{http_code}")

    if [ -n "$data" ]; then
        args+=(-d "$data")
    fi

    local response
    response=$(curl "${args[@]}" "$url" 2>/dev/null || true)

    API_STATUS=$(echo "$response" | tail -1)
    API_BODY=$(echo "$response" | sed '$d')

    # Check if body contains success: true
    if echo "$API_BODY" | grep -q '"success":true'; then
        API_SUCCESS=true
    else
        API_SUCCESS=false
    fi
}

# Extract a value from JSON response using grep (no jq dependency)
# Usage: json_extract '"key"' < "$json_file"
# Or:    echo "$json" | json_extract '"key"'
json_extract() {
    local key="$1"
    grep -o "${key}\s*:\s*\"[^\"]*\"" | sed "s/${key}\s*:\s*\"//;s/\"//" | head -1
}

# Extract a numeric value from JSON
json_extract_num() {
    local key="$1"
    grep -o "${key}\s*:\s*[0-9.]*" | sed "s/${key}\s*:\s*//" | head -1
}

# Wait for API to be ready
wait_for_api() {
    step "Waiting for API at ${API_BASE}/health..."
    local max_retries=30
    local retry=0
    while [ $retry -lt $max_retries ]; do
        if curl -s -o /dev/null -w "%{http_code}" "${API_BASE}/health" --connect-timeout 3 2>/dev/null | grep -q 200; then
            ok "API is ready."
            return 0
        fi
        retry=$((retry + 1))
        sleep 2
    done
    fail "API did not become ready after $max_retries attempts."
    return 1
}

# Check if Docker Compose is running
# Must cd to PROJECT_ROOT first so docker compose finds docker-compose.yml
check_docker() {
    local compose_dir="$PROJECT_ROOT"
    if ! (cd "$compose_dir" && docker compose ps 2>/dev/null | grep -q "Up"); then
        warn "Docker services don't appear to be running."
        warn "Start them with: cd $compose_dir && docker compose up -d --build"
        echo ""
        read -rp "Continue anyway? [y/N] " reply
        if [[ ! "$reply" =~ ^[Yy]$ ]]; then
            echo "Aborted."
            exit 1
        fi
    fi
}

# Run a docker compose command from the project root
# Usage: docker_compose ps
#        docker_compose exec -T app1 php artisan tinker
docker_compose() {
    (cd "$PROJECT_ROOT" && docker compose "$@")
}

# Print test summary
print_summary() {
    local task_name="$1"
    echo ""
    echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "  Task: ${BOLD}${task_name}${NC}"
    echo -e "  ${GREEN}Passed: ${PASS_COUNT}${NC}  |  ${RED}Failed: ${FAIL_COUNT}${NC}"
    echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

# Handles: "field": "value"  (string)
# Usage: echo "$json" | parse_json_string "field"
parse_json_string() {
    local key="$1"
    grep -o "\"$key\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" | sed "s/\"$key\"[[:space:]]*:[[:space:]]*\"//;s/\"$//" | head -1
}

# Handles: "field": 123  (numeric)
# Usage: echo "$json" | parse_json_number "field"
parse_json_number() {
    local key="$1"
    grep -o "\"$key\"[[:space:]]*:[[:space:]]*[0-9.]*" | sed "s/\"$key\"[[:space:]]*:[[:space:]]*//" | head -1
}

# Register a user and set TOKEN
# Usage: register_user <email> <name>
register_user() {
    local email="$1"
    local name="$2"
    local password="password123"

    api_call POST /auth/register "{\"name\":\"$name\",\"email\":\"$email\",\"password\":\"$password\",\"password_confirmation\":\"$password\"}"

    TOKEN=$(echo "$API_BODY" | parse_json_string "token")

    if [ -z "$TOKEN" ] || [ "$API_STATUS" != "201" ]; then
        # Try login instead (user might already exist)
        TOKEN=$(login_user "$email" "$password")
    fi

    echo "$TOKEN"
}

# Login and return token
# Usage: login_user <email> [password]
login_user() {
    local email="$1"
    local password="${2:-password123}"

    api_call POST /auth/login "{\"email\":\"$email\",\"password\":\"$password\"}"
    echo "$API_BODY" | parse_json_string "token"
}

# Get product ID (first active product, or create one)
get_product_id() {
    api_call GET /products '{"per_page":1}'
    local product_id
    product_id=$(echo "$API_BODY" | parse_json_number "id")

    if [ -z "$product_id" ] || [ "$product_id" = "null" ] || [ "$product_id" = "0" ]; then
        # No product exists — this will fail in test mode, so inform user
        echo ""
    else
        echo "$product_id"
    fi
}

# Add item to cart
add_to_cart() {
    local product_id="$1"
    local quantity="${2:-1}"

    api_call POST /cart/items "{\"product_id\":$product_id,\"quantity\":$quantity}"
    echo "$API_BODY"
}

# Place an order
place_order() {
    api_call POST /orders '{"shipping_address":{"name":"Test User","street":"123 Test St","city":"Test City","country":"US","zip":"10001"}}'
    echo "$API_BODY"
}

# Get the cart item ID
get_cart_item_id() {
    api_call GET /cart
    echo "$API_BODY" | parse_json_number "id"
}
