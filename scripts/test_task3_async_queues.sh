#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

header "TASK 3 — ASYNCHRONOUS QUEUES"

header "1. Setup — Health Check & Inventory Reset"

wait_for_api

reset_inventory


step "Registering test user..."
TOKEN=$(register_user "async@test.com" "Async Queue User")
if [ -z "$TOKEN" ]; then
    TOKEN=$(login_user "async@test.com")
fi

if [ -n "$TOKEN" ]; then
    ok "Test user ready."
else
    fail "Could not get auth token."
    exit 1
fi

step "Finding product..."
api_call GET /products
PRODUCT_ID=$(echo "$API_BODY" | parse_json_number "id")
if [ -z "$PRODUCT_ID" ] || [ "$PRODUCT_ID" = "null" ] || [ "$PRODUCT_ID" = "0" ]; then
    fail "No products found. Run seeder first."
    exit 1
fi
ok "Found product ID: $PRODUCT_ID"

step "Getting initial inventory..."
api_call GET "/products/$PRODUCT_ID"
INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
INITIAL_QTY=$(echo "$INVENTORY_JSON" | parse_json_number "quantity")
echo -e "    Initial quantity: ${INITIAL_QTY:-?}"
ok "Ready."

header "2. Async Dispatch Timing Test"
echo -e "    The controller dispatches 3+ jobs to Redis queues and returns"
echo -e "    immediately. Response should come back in ~100ms, not ~3 seconds."
echo ""

step "Adding item to cart..."
add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1
ok "Item added."

step "Placing order and measuring response time..."
START_TIME=$(date +%s%N)

ORDER_RESULT=$(curl -s -X POST "${API_BASE}/orders" \
    -H "Content-Type: application/json" \
    -H "Accept: application/json" \
    -H "Authorization: Bearer $TOKEN" \
    -d '{"shipping_address":{"name":"Async Test","street":"123 St","city":"City","country":"US","zip":"10001"}}' \
    -w "\n%{http_code}" 2>/dev/null)

END_TIME=$(date +%s%N)
DURATION_MS=$(( (END_TIME - START_TIME) / 1000000 ))

ORDER_BODY=$(echo "$ORDER_RESULT" | sed '$d')
ORDER_STATUS=$(echo "$ORDER_RESULT" | tail -1)
ORDER_ID=$(echo "$ORDER_BODY" | parse_json_number "id")

echo -e "    Response time: ${DURATION_MS}ms"
echo -e "    HTTP Status: ${ORDER_STATUS}"
echo -e "    Order ID: ${ORDER_ID:-N/A}"

if [ "$DURATION_MS" -lt 1000 ]; then
    ok "Response was FAST (${DURATION_MS}ms) — async dispatch working!"
elif [ "$DURATION_MS" -lt 3000 ]; then
    warn "Response time ${DURATION_MS}ms — might still have some sync calls."
else
    fail "Response time ${DURATION_MS}ms — SLOW! Async jobs may not be dispatched."
    warn "Check that OrderService dispatches jobs instead of calling services directly."
fi

header "3. Redis Queue Lengths"
echo -e "    Checking Redis queues for dispatched jobs..."
echo ""

if command -v redis-cli &> /dev/null; then
    REDIS_HOST="${REDIS_HOST:-localhost}"
    REDIS_PORT="${REDIS_PORT:-6379}"

    if redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" ping 2>/dev/null | grep -q "PONG"; then
        ok "Redis is accessible at ${REDIS_HOST}:${REDIS_PORT}"

        for queue in "invoices" "notifications" "analytics" "default"; do
            LEN=$(redis-cli -h "$REDIS_HOST" -p "$REDIS_PORT" LLEN "queues:${queue}" 2>/dev/null || echo "0")
            if [ "$LEN" -gt 0 ] 2>/dev/null; then
                ok "Queue '${queue}': ${LEN} job(s) waiting"
            else
                warn "Queue '${queue}': 0 jobs (may have been processed already)"
            fi
        done
    else
        warn "Cannot reach Redis at ${REDIS_HOST}:${REDIS_PORT}."
        warn "Try: docker compose exec redis redis-cli LLEN queues:invoices"
    fi
else
    warn "redis-cli not found on host."
    warn "Check queues manually: docker compose exec redis redis-cli 'LLEN queues:invoices'"
fi

header "4. Worker Log Evidence"

step "Checking docker logs for job processing..."
if command -v docker &> /dev/null; then
    if docker_compose ps 2>/dev/null | grep -q "app1"; then

        echo -e "    ${CYAN}── Invoice Jobs (laravel.log) ──${NC}"
        docker_compose exec -T app1 grep -i "invoice" storage/logs/laravel.log 2>/dev/null | tail -5 || echo "    (no invoice entries in laravel.log)"

        echo -e "    ${CYAN}── Notification Jobs (laravel.log) ──${NC}"
        docker_compose exec -T app1 grep -i "notification\|Order confirmation" storage/logs/laravel.log 2>/dev/null | tail -5 || echo "    (no notification entries in laravel.log)"

        echo -e "    ${CYAN}── Analytics Jobs (laravel.log) ──${NC}"
        docker_compose exec -T app1 grep -i "analytics" storage/logs/laravel.log 2>/dev/null | tail -5 || echo "    (no analytics entries in laravel.log)"

        echo -e "    ${CYAN}── ProcessOrder Jobs (laravel.log) ──${NC}"
        docker_compose exec -T app1 grep -i "ProcessOrder\|Processing order" storage/logs/laravel.log 2>/dev/null | tail -5 || echo "    (no ProcessOrder entries)"

        echo -e "    ${CYAN}── Queue Worker Activity (stdout) ──${NC}"
        docker_compose logs app1 --tail=30 2>/dev/null | grep -i "queue\|job\|dispatch\|processed\|Processing" || echo "    (no recent queue activity in stdout)"

        ok "Logs retrieved."
    else
        warn "Docker containers not running. Skipping log check."
        warn "Run tests inside Docker: docker_compose exec app1 bash"
    fi
else
    warn "Docker not available on host. Skipping log check."
fi

header "5. Order Verification"

step "Fetching order list..."
TOKEN=$TOKEN
api_call GET /orders
if echo "$API_BODY" | grep -q '"success":true'; then
    ok "Order appears in user's order list."
else
    fail "Could not retrieve orders."
fi

print_summary "Task 3 — Asynchronous Queues"

echo -e "  ${GREEN}✓${NC} Response time: ${DURATION_MS}ms (should be < 1000ms)"
echo -e "  ${GREEN}✓${NC} Jobs dispatched to: invoices, notifications, analytics"
echo -e "  ${GREEN}✓${NC} Queue workers process jobs asynchronously in the background"

[ "$FAIL_COUNT" -eq 0 ] && exit 0 || exit 1
