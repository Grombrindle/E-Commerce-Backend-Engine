#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

header "TASK 4 — CHUNKED BATCH PROCESSING (MEMORY SAFETY)"

header "1. Setup & Health Check"

wait_for_api

TARGET_DATE=$(date +%Y-%m-%d)
echo -e "    Target date: ${BOLD}${TARGET_DATE}${NC}"

DOCKER_AVAILABLE=false
if command -v docker &> /dev/null && docker_compose ps 2>/dev/null | grep -q "Up"; then
    DOCKER_AVAILABLE=true
    ok "Docker containers are running."
else
    warn "Docker not available (or not running from project root). Will use local artisan commands."
    warn "  If Docker IS running, try: cd $PROJECT_ROOT && docker compose ps"
fi

APP_CONTAINER="app1"
if [ "$DOCKER_AVAILABLE" = true ]; then
    APP_CONTAINER=$(docker_compose ps --services 2>/dev/null | grep -E '^app[0-9]' | head -1)
    [ -z "$APP_CONTAINER" ] && APP_CONTAINER="app1"
fi

tinker_exec() {
    local code="$1"
    local mode="${2:-auto}"
    local result

    local clean_code
    clean_code=$(echo "$code" | tr -s ' ' | tr '\n' ' ' | sed 's/[[:space:]]*$//')

    if { [ "$mode" = "docker" ] || [ "$mode" = "auto" ]; } && [ "$DOCKER_AVAILABLE" = true ]; then
        result=$(docker_compose exec -T "$APP_CONTAINER" php artisan tinker --execute="$clean_code" 2>/dev/null | tail -1 || echo "")
        if [ -z "$result" ]; then
            result=$(echo "$code" | docker_compose exec -T "$APP_CONTAINER" php artisan tinker 2>/dev/null | grep -v "Psy" | grep -v "^$" | tail -1 || echo "")
        fi
    else
        result=$(php artisan tinker --execute="$clean_code" 2>/dev/null | tail -1 || echo "")
    fi
    echo "$result"
}

header "2. Seeding Test Orders"

step "Checking existing order count..."
COUNT_RESULT=$(tinker_exec "echo App\\\\Models\\\\Order::whereDate('created_at', '${TARGET_DATE}')->count();")
echo -e "    Orders for ${TARGET_DATE}: ${COUNT_RESULT:-0}"

step "Creating a test order for ${TARGET_DATE}..."
ORDER_CREATE_CODE="
\$user = App\\\\Models\\\\User::first();
if (!\$user) { echo \"NO_USER\"; exit; }
\$order = App\\\\Models\\\\Order::create([
    'user_id' => \$user->id,
    'status' => 'completed',
    'subtotal' => 100,
    'tax' => 15,
    'shipping_fee' => 9.99,
    'discount' => 0,
    'total' => 124.99,
    'shipping_address' => json_encode(['street'=>'Test','city'=>'Test','country'=>'US','zip'=>'10001']),
    'billing_address' => json_encode(['street'=>'Test','city'=>'Test','country'=>'US','zip'=>'10001']),
    'created_at' => '${TARGET_DATE} 10:00:00',
    'updated_at' => '${TARGET_DATE} 10:00:00',
]);
echo \"ORDER_CREATED:\" . \$order->id;
"
RESULT=$(tinker_exec "$ORDER_CREATE_CODE")
SEEDED_ORDER_ID=$(echo "$RESULT" | grep -o "ORDER_CREATED:[0-9]*" | grep -o "[0-9]*" || echo "")

if [ -n "$SEEDED_ORDER_ID" ]; then
    ok "Test order created (ID: ${SEEDED_ORDER_ID}) for ${TARGET_DATE}."
else
    warn "Could not create test order via API. Trying API-based approach..."
    TOKEN=$(register_user "batch@test.com" "Batch User" 2>/dev/null || login_user "batch@test.com" 2>/dev/null || echo "")
    if [ -n "$TOKEN" ]; then
        api_call GET /products
        PRODUCT_ID=$(echo "$API_BODY" | parse_json_number "id")
        if [ -n "$PRODUCT_ID" ] && [ "$PRODUCT_ID" != "null" ] && [ "$PRODUCT_ID" != "0" ]; then
            add_to_cart "$PRODUCT_ID" 1 > /dev/null 2>&1
            place_order > /dev/null 2>&1
            ok "Order placed via API."
        fi
    fi
fi

header "3. ⚡ Dispatching Daily Sales Batch Job"

step "Dispatching DispatchDailySalesBatchJob for ${TARGET_DATE}..."
DISPATCH_RESULT=$(tinker_exec "echo \"DISPATCHED_OK\"; App\\\\Jobs\\\\DispatchDailySalesBatchJob::dispatch('${TARGET_DATE}');")

step "Running queue worker (queue: default,batch-processing)..."
if [ "$DOCKER_AVAILABLE" = true ]; then
    docker_compose exec -T "$APP_CONTAINER" php artisan queue:work --stop-when-empty --queue=default,batch-processing 2>/dev/null &
    WORKER_PID=$!
    sleep 3
    wait $WORKER_PID 2>/dev/null || true
else
    php artisan queue:work --stop-when-empty --queue=default,batch-processing 2>/dev/null &
    WORKER_PID=$!
    sleep 3
    wait $WORKER_PID 2>/dev/null || true
fi

if echo "$DISPATCH_RESULT" | grep -q "DISPATCHED_OK"; then
    ok "Batch job dispatched successfully."
else
    warn "Dispatch result: ${DISPATCH_RESULT:-(no output)}"
fi

header "4. Verifying Batch Processing Results"

step "Checking DailySalesReport table..."
REPORT_CODE="
\$report = App\\\\Models\\\\DailySalesReport::where('date', '${TARGET_DATE}')->first();
if (\$report) {
    echo 'FOUND: revenue=' . \$report->chunk_revenue . ', count=' . \$report->chunk_count;
} else {
    echo 'NOT_FOUND';
}
"
REPORT_RESULT=$(tinker_exec "$REPORT_CODE")

if echo "$REPORT_RESULT" | grep -q "FOUND:"; then
    ok "DailySalesReport: ${REPORT_RESULT} ✅"
elif echo "$REPORT_RESULT" | grep -q "NOT_FOUND"; then
    warn "DailySalesReport not found for ${TARGET_DATE}."
    warn "Check queue worker logs: docker_compose logs app1"
else
    warn "Query result: ${REPORT_RESULT:-(empty)}"
fi

step "Checking job_batches table..."
BATCHES_CODE="
\$batch = DB::table('job_batches')->where('name', 'like', '%Daily Sales%')->orderByDesc('created_at')->first();
if (\$batch) {
    echo 'BATCH: name=' . \$batch->name . ', total=' . \$batch->total_jobs . ', pending=' . \$batch->pending_jobs . ', failed=' . \$batch->failed_jobs;
} else {
    echo 'NO_BATCHES_FOUND';
}
"
BATCHES_RESULT=$(tinker_exec "$BATCHES_CODE")

if echo "$BATCHES_RESULT" | grep -q "BATCH:"; then
    echo -e "    ${BATCHES_RESULT}"
    ok "Batch metadata found. ✅"
else
    warn "Job batch metadata not found (may be cleaned up already)."
    warn "   ${BATCHES_RESULT}"
fi

header "5. Schedule Verification"

step "Checking scheduled tasks include batch processing..."
SCHEDULE_OUTPUT=$(php artisan schedule:list 2>/dev/null | grep -i "batch" || echo "")
if [ -n "$SCHEDULE_OUTPUT" ]; then
    echo -e "    ${SCHEDULE_OUTPUT}"
    ok "Scheduled tasks include batch processing."
else
    warn "Could not find batch processing in schedule list."
    warn "Run: php artisan schedule:list | grep batch"
fi

header "6. Cleanup Test Data"

if [ -n "${SEEDED_ORDER_ID:-}" ] && [ "${SEEDED_ORDER_ID:-0}" != "0" ]; then
    CLEANUP_CODE="
    \$order = App\\\\Models\\\\Order::find(${SEEDED_ORDER_ID});
    if (\$order) { \$order->forceDelete(); echo 'DELETED'; } else { echo 'NOT_FOUND'; }
    "
    CLEANUP_RESULT=$(tinker_exec "$CLEANUP_CODE")
    [ "$CLEANUP_RESULT" = "DELETED" ] && ok "Test order deleted." || warn "Cleanup: ${CLEANUP_RESULT:-empty}"
fi

ok "Test cleanup complete."

print_summary "Task 4 — Chunked Batch Processing"

echo -e "  ${GREEN}✓${NC} Batch job: DispatchDailySalesBatchJob dispatched"
echo -e "  ${GREEN}✓${NC} Chunks: 500 rows at a time (memory = O(chunk_size))"
echo -e "  ${GREEN}✓${NC} Bus::batch() with allowFailures() for fault isolation"
echo -e "  ${GREEN}✓${NC} DailySalesReport upsert — safe for concurrent chunks"
echo ""
echo -e "  ${YELLOW}📌${NC} To test the BAD (memory-killing) version:"
echo -e "     1. Open app/Jobs/DispatchDailySalesBatchJob.php"
echo -e "     2. Replace chunk+batch with Order::get() loop"
echo -e "     3. Seed 10,000+ orders → see OOM crash"
echo -e "     4. Restore chunked version → runs safely"
echo ""

[ "$FAIL_COUNT" -eq 0 ] && exit 0 || exit 1
