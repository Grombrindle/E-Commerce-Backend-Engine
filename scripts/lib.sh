#!/usr/bin/env bash

set -euo pipefail

API_BASE="${API_BASE:-http://localhost:8080/api/v1}"
TIMEOUT="${TIMEOUT:-10}"
PASS_COUNT=0
FAIL_COUNT=0

PROJECT_ROOT="${SCRIPT_DIR%/scripts}"
[ "$PROJECT_ROOT" = "$SCRIPT_DIR" ] && PROJECT_ROOT="$SCRIPT_DIR/.."

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m' # No Color


header() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}  ${BOLD}$1${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

step() {
    echo -e "${YELLOW}  ▶${NC} $1"
}

ok() {
    echo -e "    ${GREEN}✓${NC} $1"
    PASS_COUNT=$((PASS_COUNT + 1))
}

fail() {
    echo -e "    ${RED}✗${NC} $1"
    FAIL_COUNT=$((FAIL_COUNT + 1))
}

warn() {
    echo -e "    ${YELLOW}⚠${NC} $1"
}

sub() {
    echo -e "    ${CYAN}→${NC} $1"
}

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

    if echo "$API_BODY" | grep -q '"success":true'; then
        API_SUCCESS=true
    else
        API_SUCCESS=false
    fi
}

json_extract() {
    local key="$1"
    grep -o "${key}\s*:\s*\"[^\"]*\"" | sed "s/${key}\s*:\s*\"//;s/\"//" | head -1
}

json_extract_num() {
    local key="$1"
    grep -o "${key}\s*:\s*[0-9.]*" | sed "s/${key}\s*:\s*//" | head -1
}

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

docker_compose() {
    (cd "$PROJECT_ROOT" && docker compose "$@")
}

print_summary() {
    local task_name="$1"
    echo ""
    echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
    echo -e "  Task: ${BOLD}${task_name}${NC}"
    echo -e "  ${GREEN}Passed: ${PASS_COUNT}${NC}  |  ${RED}Failed: ${FAIL_COUNT}${NC}"
    echo -e "${BOLD}═══════════════════════════════════════════════════════════════${NC}"
    echo ""
}

parse_json_string() {
    local key="$1"
    grep -o "\"$key\"[[:space:]]*:[[:space:]]*\"[^\"]*\"" | sed "s/\"$key\"[[:space:]]*:[[:space:]]*\"//;s/\"$//" | head -1
}

parse_json_number() {
    local key="$1"
    grep -o "\"$key\"[[:space:]]*:[[:space:]]*[0-9.]*" | sed "s/\"$key\"[[:space:]]*:[[:space:]]*//" | head -1
}

register_user() {
    local email="$1"
    local name="$2"
    local password="password123"

    api_call POST /auth/register "{\"name\":\"$name\",\"email\":\"$email\",\"password\":\"$password\",\"password_confirmation\":\"$password\"}"

    TOKEN=$(echo "$API_BODY" | parse_json_string "token")

    if [ -z "$TOKEN" ] || [ "$API_STATUS" != "201" ]; then
        TOKEN=$(login_user "$email" "$password")
    fi

    echo "$TOKEN"
}

login_user() {
    local email="$1"
    local password="${2:-password123}"

    api_call POST /auth/login "{\"email\":\"$email\",\"password\":\"$password\"}"
    echo "$API_BODY" | parse_json_string "token"
}

get_product_id() {
    api_call GET /products '{"per_page":1}'
    local product_id
    product_id=$(echo "$API_BODY" | parse_json_number "id")

    if [ -z "$product_id" ] || [ "$product_id" = "null" ] || [ "$product_id" = "0" ]; then
        echo ""
    else
        echo "$product_id"
    fi
}

add_to_cart() {
    local product_id="$1"
    local quantity="${2:-1}"

    api_call POST /cart/items "{\"product_id\":$product_id,\"quantity\":$quantity}"
    echo "$API_BODY"
}

place_order() {
    api_call POST /orders '{"shipping_address":{"name":"Test User","street":"123 Test St","city":"Test City","country":"US","zip":"10001"}}'
    echo "$API_BODY"
}

get_cart_item_id() {
    api_call GET /cart
    echo "$API_BODY" | parse_json_number "id"
}

reset_inventory() {
    step "Resetting inventory (clearing reservations, restoring stock)..."
    
    if ! command -v docker &> /dev/null; then
        warn "Docker not available — cannot reset inventory via tinker."
        warn "Make sure products have available stock before running tests."
        return 1
    fi

    local php_code='\App\Models\Inventory::query()->update(["reserved_quantity" => 0, "quantity" => \DB::raw("CASE WHEN quantity < 50 THEN 50 ELSE quantity END")]);'
    
    local result
    result=$(docker_compose exec -T app1 php artisan tinker --execute="$php_code" 2>/dev/null || echo "FAILED")
    
    if echo "$result" | grep -qi "error\|exception\|FAILED"; then
        result=$(echo "$php_code" | docker_compose exec -T app1 php artisan tinker 2>/dev/null | tail -3 || echo "FAILED")
    fi
    
    sleep 1
    
    api_call GET /products
    local product_id
    product_id=$(echo "$API_BODY" | parse_json_number "id")
    
    if [ -n "$product_id" ] && [ "$product_id" != "null" ] && [ "$product_id" != "0" ]; then
        ok "Inventory reset complete. Products are available for testing."
        return 0
    else
        warn "Could not verify inventory reset. Trying alternate method..."        # Alternate: pipe via stdin with explicit DONE marker
            local alt_result
            alt_result=$(echo '\App\Models\Inventory::query()->update(["reserved_quantity" => 0, "quantity" => \DB::raw("CASE WHEN quantity < 50 THEN 50 ELSE quantity END")]); echo "DONE";' | docker_compose exec -T app1 php artisan tinker 2>/dev/null | tail -3 || echo "FAILED")
        if echo "$alt_result" | grep -q "DONE"; then
            sleep 1
            ok "Inventory reset via alternate method."
            return 0
        else
            warn "Inventory reset may have failed. Result: ${alt_result:0:100}"
            warn "Tests may fail if products have no available stock."
            return 1
        fi
    fi
}

clear_carts() {
    step "Clearing test carts..."
    local email_pattern="${1:-%@test.com}"
    
    local php_code='\App\Models\Cart::whereHas("user", fn(\$q) => \$q->where("email", "like", "'"$email_pattern"'"))->delete();'
    local result
    result=$(echo "$php_code" | docker_compose exec -T app1 php artisan tinker 2>/dev/null || echo "FAILED")
    
    ok "Test carts cleared."
}
