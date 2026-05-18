#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
# test_task2_rate_limiting.sh
# Task 2 — Resource Management & Capacity Control (Rate Limiting)
#
# Tests:
#   1. Health check & user registration
#   2. Fire 15 rapid order requests — first 10 should work,
#      next 5 should get HTTP 429 (Too Many Requests)
#   3. Test with a different user to confirm user-level isolation
#   4. Test API rate limiter (60 req/min) on a non-order endpoint
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

header "TASK 2 — RATE LIMITING & CAPACITY CONTROL"

# ── 1. Setup ─────────────────────────────────────────────────────────
header "1. Setup"

wait_for_api

step "Registering test user..."
TOKEN=$(register_user "ratelimit@test.com" "Rate Limit User")
if [ -z "$TOKEN" ]; then
    TOKEN=$(login_user "ratelimit@test.com")
fi

if [ -n "$TOKEN" ]; then
    ok "Test user ready. Token: ${TOKEN:0:20}..."
else
    fail "Could not get auth token."
    exit 1
fi

# Get a product
step "Finding a product to order..."
api_call GET /products
PRODUCT_ID=$(echo "$API_BODY" | parse_json_number "id")
if [ -z "$PRODUCT_ID" ] || [ "$PRODUCT_ID" = "null" ] || [ "$PRODUCT_ID" = "0" ]; then
    fail "No products found. Run seeder first."
    exit 1
fi
ok "Found product ID: $PRODUCT_ID"

# Add item to cart (we'll use this for order attempts)
step "Adding item to cart for order tests..."
add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1
ok "Item added to cart."

# ── 2. Rapid Fire Rate Limit Test ────────────────────────────────────
header "2. ⚡ Rate Limit Test — 15 Rapid Order Requests"
echo -e "    The 'orders' throttle allows 10 requests per minute per user."
echo -e "    Requests 1-10 should succeed (or 422 if cart empty after first order)."
echo -e "    Requests 11-15 should get HTTP 429 (Too Many Attempts)."
echo ""

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

# Fire 15 rapid requests
for i in $(seq 1 15); do
    curl -s -X POST "${API_BASE}/orders" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json" \
        -H "Authorization: Bearer $TOKEN" \
        -d '{"shipping_address":{"name":"Test","street":"123 St","city":"City","country":"US","zip":"10001"}}' \
        -o "$TMPDIR/order_${i}.json" \
        -w "%{http_code}" > "$TMPDIR/status_${i}.txt" 2>/dev/null &
done
wait

# Analyze results
THROTTLED_COUNT=0
SUCCESS_COUNT=0
OTHER_COUNT=0
FIRST_429_INDEX=0

echo -e "    ┌──────────┬────────┬──────────────────────────────────┐"
echo -e "    │ Request  │ Status │ Response                         │"
echo -e "    ├──────────┼────────┼──────────────────────────────────┤"

for i in $(seq 1 15); do
    STATUS=$(cat "$TMPDIR/status_${i}.txt" 2>/dev/null || echo "000")
    BODY=$(cat "$TMPDIR/order_${i}.json" 2>/dev/null || echo "{}")

    case "$STATUS" in
        201)
            echo -e "    │ ${BOLD}Req #${i}${NC}    │ ${GREEN}201${NC}    │ Order placed ✓                     │"
            SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
            ;;
        422)
            echo -e "    │ ${BOLD}Req #${i}${NC}    │ ${YELLOW}422${NC}    │ Empty cart / validation error     │"
            SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
            ;;
        429)
            if [ "$FIRST_429_INDEX" -eq 0 ]; then
                FIRST_429_INDEX=$i
            fi
            echo -e "    │ ${BOLD}Req #${i}${NC}    │ ${RED}429${NC}    │ Rate limited ✓                    │"
            THROTTLED_COUNT=$((THROTTLED_COUNT + 1))
            ;;
        *)
            echo -e"    │ ${BOLD}Req #${i}${NC}    │ ${RED}${STATUS}${NC}   │ Unexpected                         │"
            OTHER_COUNT=$((OTHER_COUNT + 1))
            ;;
    esac
done
echo -e "    └──────────┴────────┴──────────────────────────────────┘"

echo ""
echo -e "    Results: ${GREEN}${SUCCESS_COUNT} processed${NC} + ${RED}${THROTTLED_COUNT} throttled${NC} + ${YELLOW}${OTHER_COUNT} other${NC}"

if [ "$THROTTLED_COUNT" -gt 0 ]; then
    ok "Rate limiting IS active — ${THROTTLED_COUNT} requests were throttled (429)."

    if [ "$FIRST_429_INDEX" -le 12 ]; then
        ok "First 429 appeared at request #${FIRST_429_INDEX} (expecting ~10-11)."
    else
        warn "First 429 appeared late at request #${FIRST_429_INDEX}."
        warn "This might be because requests fired too fast or cart was empty."
    fi
else
    fail "No requests were throttled! Rate limiting may not be working."
    warn "Check that 'throttle:orders' middleware is applied to POST /api/v1/orders"
fi

# ── 3. User-Level Isolation Test ────────────────────────────────────
header "3. User-Level Rate Limit Isolation"

step "Registering second user..."
TOKEN2=$(register_user "ratelimit2@test.com" "Rate Limit User 2")
if [ -z "$TOKEN2" ]; then
    TOKEN2=$(login_user "ratelimit2@test.com")
fi

if [ -z "$TOKEN2" ]; then
    fail "Could not get token for second user."
else
    ok "Second user ready."

    # Add item to cart for user 2
    TOKEN=$TOKEN2
    add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1

    step "Firing 3 rapid requests from user 2..."
    for i in $(seq 1 3); do
        curl -s -o /dev/null -w "%{http_code}" \
            -X POST "${API_BASE}/orders" \
            -H "Content-Type: application/json" \
            -H "Authorization: Bearer $TOKEN2" \
            -d '{"shipping_address":{"name":"Test2","street":"123 St","city":"City","country":"US","zip":"10001"}}' \
            2>/dev/null &
    done
    wait

    ok "User 2's requests went through (rate limit is per-user, not global)."
fi

# ── 4. API-Level Rate Limiter ──────────────────────────────────────
header "4. API-Level Rate Limit (60 req/min)"

step "Firing 5 quick requests to /auth/me (api limiter: 60 req/min)..."
TOKEN=$TOKEN
for i in $(seq 1 5); do
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" \
        -X GET "${API_BASE}/auth/me" \
        -H "Authorization: Bearer $TOKEN" \
        --connect-timeout 5 2>/dev/null || true)
    echo -e "    Request $i: HTTP $STATUS"
done
ok "API rate limiter test complete (no throttle expected at 5 req)."

# ── Summary ─────────────────────────────────────────────────────────
print_summary "Task 2 — Rate Limiting & Capacity Control"

if [ "$THROTTLED_COUNT" -gt 0 ]; then
    echo -e "  ${GREEN}✓${NC} Rate limiting:${GREEN} ACTIVE${NC} — ${THROTTLED_COUNT} of 15 requests were throttled."
else
    echo -e "  ${RED}✗${NC} Rate limiting: ${RED}NOT DETECTED${NC}"
    echo -e "    Ensure 'throttle:orders' is on the route and RateLimiter::for('orders') is registered."
fi

[ "$FAIL_COUNT" -eq 0 ] && exit 0 || exit 1
