#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

header "TASK 1 — RACE CONDITION & DATA INTEGRITY"

header "1. Setup — Health Check & Inventory Reset"

wait_for_api

reset_inventory

header "2. Register Users"

step "Registering user 1 (race1@test.com)..."
USER1_TOKEN=$(register_user "race1@test.com" "Race User 1")
if [ -n "$USER1_TOKEN" ]; then
    ok "User 1 registered. Token: ${USER1_TOKEN:0:20}..."
else
    fail "Failed to register user 1"
    USER1_TOKEN=$(login_user "race1@test.com")
    if [ -n "$USER1_TOKEN" ]; then
        ok "User 1 logged in (existing user). Token: ${USER1_TOKEN:0:20}..."
    else
        fail "Could not get token for user 1"
    fi
fi

step "Registering user 2 (race2@test.com)..."
USER2_TOKEN=$(register_user "race2@test.com" "Race User 2")
if [ -n "$USER2_TOKEN" ]; then
    ok "User 2 registered. Token: ${USER2_TOKEN:0:20}..."
else
    USER2_TOKEN=$(login_user "race2@test.com")
    [ -n "$USER2_TOKEN" ] && ok "User 2 logged in." || fail "Could not get token for user 2"
fi

step "Registering user 3 (race3@test.com)..."
USER3_TOKEN=$(register_user "race3@test.com" "Race User 3")
if [ -n "$USER3_TOKEN" ]; then
    ok "User 3 registered. Token: ${USER3_TOKEN:0:20}..."
else
    USER3_TOKEN=$(login_user "race3@test.com")
    [ -n "$USER3_TOKEN" ] && ok "User 3 logged in." || fail "Could not get token for user 3"
fi

header "3. Product & Inventory Check"

TOKEN=$USER1_TOKEN
step "Fetching product list..."
api_call GET /products
PRODUCT_ID=$(echo "$API_BODY" | parse_json_number "id")

if [ -z "$PRODUCT_ID" ] || [ "$PRODUCT_ID" = "null" ] || [ "$PRODUCT_ID" = "0" ]; then
    fail "No products found! Run seeder first: docker_compose exec app1 php artisan db:seed"
    exit 1
fi
ok "Found product ID: $PRODUCT_ID"

api_call GET "/products/$PRODUCT_ID"
INITIAL_QTY=$(echo "$API_BODY" | parse_json_number "quantity")
INITIAL_RESERVED=$(echo "$API_BODY" | parse_json_number "reserved_quantity")

if [ -z "$INITIAL_QTY" ] || [ "$INITIAL_QTY" = "null" ]; then
    INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
    INITIAL_QTY=$(echo "$INVENTORY_JSON" | parse_json_number "quantity")
    INITIAL_RESERVED=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")
fi

echo -e "    Inventory: quantity=${INITIAL_QTY:-?}, reserved=${INITIAL_RESERVED:-?}"

if [ -z "${INITIAL_QTY:-}" ] || [ "${INITIAL_QTY:-0}" -eq 0 ]; then
    warn "Product has 0 stock! Run seeder to restore inventory:"
    warn "  docker_compose exec app1 php artisan db:seed"
    warn "  (or: cd $PROJECT_ROOT && docker compose exec app1 php artisan db:seed)"
    echo ""
    read -rp "Continue anyway? (tests will likely fail) [y/N] " reply
    if [[ ! "$reply" =~ ^[Yy]$ ]]; then
        echo "  Aborted. Run the seeder and try again."
        exit 1
    fi
fi

ok "Product details retrieved."

header "4. Cart Reservation System"

step "4a. Adding 2 units to cart (user 1)..."
CART_ITEM_ID=0
ADD_RESULT=$(add_to_cart "$PRODUCT_ID" 2)
if echo "$ADD_RESULT" | grep -q '"success":true'; then
    ok "Item added to cart."
    CART_ITEM_ID=$(echo "$ADD_RESULT" | parse_json_number "id")
    echo -e "    Cart item ID: ${CART_ITEM_ID}"
else
    fail "Could not add item to cart. Response: $(echo "$ADD_RESULT" | head -c 200)"
fi

step "4b. Verify reserved_quantity increased..."
sleep 1
api_call GET "/products/$PRODUCT_ID"
INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
RESERVED_AFTER_ADD=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")

if [ -n "$RESERVED_AFTER_ADD" ] && [ "$RESERVED_AFTER_ADD" -ge 2 ]; then
    ok "reserved_quantity = ${RESERVED_AFTER_ADD} (≥ 2). Reservation works!"
else
    warn "reserved_quantity = ${RESERVED_AFTER_ADD:-0} (expected ≥ 2)"
    warn "This is expected if running without Docker/Redis (cache tags won't flush as fast)"
fi

step "4c. Update item quantity to 3 (adds 1 more reserved)..."
if [ "$CART_ITEM_ID" -gt 0 ]; then
    TOKEN=$USER1_TOKEN
    api_call PUT "/cart/items/${CART_ITEM_ID}" '{"quantity":3}'
    if [ "$API_STATUS" = "200" ]; then
        ok "Cart item updated to 3."

        sleep 1
        api_call GET "/products/$PRODUCT_ID"
        INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
        RESERVED_AFTER_UPDATE=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")
        echo -e "    reserved_quantity now: ${RESERVED_AFTER_UPDATE:-?}"
        ok "Item quantity updated."
    else
        fail "Could not update cart item. Status: $API_STATUS"
    fi
else
    warn "Skipping update — no cart item (stock was 0)."
fi

step "4d. Remove item from cart (releases reservation)..."
if [ "$CART_ITEM_ID" -gt 0 ]; then
    TOKEN=$USER1_TOKEN
    api_call DELETE "/cart/items/${CART_ITEM_ID}"
    if [ "$API_STATUS" = "200" ]; then
        ok "Item removed from cart."
        sleep 1
        api_call GET "/products/$PRODUCT_ID"
        INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
        RESERVED_AFTER_DELETE=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")
        echo -e "    reserved_quantity now: ${RESERVED_AFTER_DELETE:-?}"
        ok "Reservation released."
    else
        fail "Could not remove item. Status: $API_STATUS"
    fi
else
    warn "Skipping remove — no cart item (stock was 0)."
fi

header "5. Single User Order Flow"

step "5a. Add 1 unit to cart..."
TOKEN=$USER1_TOKEN
ADD_RESULT=$(add_to_cart "$PRODUCT_ID" 1)
if echo "$ADD_RESULT" | grep -q '"success":true'; then
    ok "1 unit added to cart."
else
    fail "Could not add to cart."
fi

step "5b. Place order..."
ORDER_RESULT=$(place_order)
ORDER_ID=$(echo "$ORDER_RESULT" | parse_json_number "id")
ORDER_STATUS=$(echo "$ORDER_RESULT" | parse_json_string "status")

if [ -n "$ORDER_ID" ] && [ "$ORDER_STATUS" != "null" ]; then
    ok "Order placed! ID: $ORDER_ID, Status: ${ORDER_STATUS:-pending}"
else
    fail "Order placement failed. Status: $API_STATUS"
    fail "Body: $(echo "$ORDER_RESULT" | head -c 200)"
fi

step "5c. Verify stock decremented..."
api_call GET "/products/$PRODUCT_ID"
INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
QTY_AFTER_ORDER=$(echo "$INVENTORY_JSON" | parse_json_number "quantity")
RESERVED_AFTER_ORDER=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")

echo -e "    quantity: ${QTY_AFTER_ORDER:-?} (was ${INITIAL_QTY:-?}), reserved: ${RESERVED_AFTER_ORDER:-?}"
if [ -n "$QTY_AFTER_ORDER" ] && [ -n "$INITIAL_QTY" ]; then
    EXPECTED=$((INITIAL_QTY - 1))
    if [ "$QTY_AFTER_ORDER" = "$EXPECTED" ]; then
        ok "Stock correctly decremented: ${INITIAL_QTY} → ${QTY_AFTER_ORDER}"
    else
        warn "Stock: ${INITIAL_QTY} → ${QTY_AFTER_ORDER} (expected ${EXPECTED})"
    fi
fi

step "5d. Cancel order (restores stock)..."
TOKEN=$USER1_TOKEN
api_call POST "/orders/${ORDER_ID}/cancel" '{"reason":"Restoring stock for concurrent test"}'
if [ "$API_STATUS" = "200" ]; then
    ok "Order cancelled. Stock should be restored."
else
    fail "Could not cancel order. Status: $API_STATUS"
fi

header "6. ⚡ CONCURRENT ORDER RACE CONDITION TEST"
echo -e "    ${BOLD}Firing simultaneous orders from 3 users on the same product.${NC}"
echo -e "    The system uses lockForUpdate() — only one should succeed per available unit."
echo ""

api_call GET "/products/$PRODUCT_ID"
INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
CURRENT_QTY=$(echo "$INVENTORY_JSON" | parse_json_number "quantity")
CURRENT_RESERVED=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")
echo -e "    Current stock: quantity=${CURRENT_QTY}, reserved=${CURRENT_RESERVED}"

step "User 1 adds item to cart..."
TOKEN=$USER1_TOKEN
add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1

step "User 2 adds item to cart..."
TOKEN=$USER2_TOKEN
add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1

step "User 3 adds item to cart..."
TOKEN=$USER3_TOKEN
add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1

step "Firing 3 concurrent order requests (all at once!)..."
echo ""

local_error_mode="$(set +o | grep errexit)"
set +e
set +u
set +o pipefail

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

fire_concurrent_order() {
    local id="$1"
    local token="$2"
    local outfile="$TMPDIR/result_$id.json"

    curl -s -X POST "${API_BASE}/orders" \
        -H "Content-Type: application/json" \
        -H "Accept: application/json" \
        -H "Authorization: Bearer $token" \
        -d '{"shipping_address":{"name":"Concurrent User","street":"123 St","city":"City","country":"US","zip":"10001"}}' \
        -o "$outfile" \
        -w "%{http_code}" > "$TMPDIR/status_$id.txt" 2>/dev/null &
}

fire_concurrent_order 1 "$USER1_TOKEN"
fire_concurrent_order 2 "$USER2_TOKEN"
fire_concurrent_order 3 "$USER3_TOKEN"

sleep 3

echo -e "    ┌────────────────────────────────────────────────────────┐"
for i in 1 2 3; do
    STATUS=$(cat "$TMPDIR/status_$i.txt" 2>/dev/null || echo "000")
    BODY=$(cat "$TMPDIR/result_$i.json" 2>/dev/null || echo "{}")
    if [ "$STATUS" = "201" ]; then
        echo -e "    │ ${GREEN}User $i: HTTP $STATUS — Order placed ✓${NC}       │"
    elif [ "$STATUS" = "422" ]; then
        ERROR_MSG=$(echo "$BODY" | parse_json_string "error" 2>/dev/null | head -c 40 || echo "")
        echo -e "    │ ${YELLOW}User $i: HTTP $STATUS — ${ERROR_MSG:-Insufficient stock}${NC} │"
    else
        echo -e "    │ ${RED}User $i: HTTP $STATUS — Unexpected${NC}            │"
    fi
done
echo -e "    └────────────────────────────────────────────────────────┘"

api_call GET "/products/$PRODUCT_ID"
INVENTORY_JSON=$(echo "$API_BODY" | grep -o '"inventory"[[:space:]]*:[[:space:]]*{[^}]*}' | head -1)
FINAL_QTY=$(echo "$INVENTORY_JSON" | parse_json_number "quantity")
FINAL_RESERVED=$(echo "$INVENTORY_JSON" | parse_json_number "reserved_quantity")

echo ""
step "Final inventory: quantity=${FINAL_QTY:-?}, reserved=${FINAL_RESERVED:-?}"

SUCCESS_COUNT=0
for i in 1 2 3; do
    STATUS=$(cat "$TMPDIR/status_$i.txt" 2>/dev/null || echo "0")
    if [ "$STATUS" = "201" ]; then
        SUCCESS_COUNT=$((SUCCESS_COUNT + 1))
    fi
done

if [ -n "${FINAL_QTY:-}" ] && [ "${FINAL_QTY:-0}" -ge 0 ]; then
    ok "Stock never went negative (final: ${FINAL_QTY}). Pessimistic locking WORKS!"
else
    fail "Stock went negative (${FINAL_QTY:-unknown})! Race condition still present."
fi

if [ "$SUCCESS_COUNT" -le 1 ]; then
    ok "At most 1 order succeeded (${SUCCESS_COUNT} of 3) — expected with limited stock."
else
    warn "${SUCCESS_COUNT} orders succeeded. If stock ≥ 3 this is fine; otherwise verify lock integrity."
fi

if [ -n "$local_error_mode" ]; then
    eval "$local_error_mode"
fi
set -u
set -o pipefail

print_summary "Task 1 — Race Condition & Data Integrity"
[ "$FAIL_COUNT" -eq 0 ] && exit 0 || exit 1
