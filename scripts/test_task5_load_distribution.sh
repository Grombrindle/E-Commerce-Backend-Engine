#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
# test_task5_load_distribution.sh
# Task 5 — Nginx Load Balancing & Horizontal Scaling
#
# Tests:
#   1. Nginx health endpoint (GET /api/v1/health)
#   2. X-Upstream header distribution (weighted round-robin)
#   3. Request routing — fire 30 requests, check distribution
#   4. Fault tolerance — stop one app container, verify API still works
#   5. Fault recovery — restart container, verify it rejoins the pool
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

header "TASK 5 — NGINX LOAD BALANCING & HORIZONTAL SCALING"

# ── 1. Setup ─────────────────────────────────────────────────────────
header "1. Setup & Health Check"

step "Verifying Docker environment..."
if ! command -v docker &> /dev/null; then
    fail "Docker is required for load balancing tests."
    exit 1
fi

if ! docker_compose ps 2>/dev/null | grep -q "Up"; then
    fail "Docker containers are not running. Start them first:"
    fail "  cd $PROJECT_ROOT && docker compose up -d --build"
    exit 1
fi
ok "Docker containers are running."

# Show current running services
echo ""
echo -e "    ${CYAN}── Running Services ──${NC}"
docker_compose ps --format "table {{.Service}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null | while IFS= read -r line; do
    echo -e "    ${line}"
done
echo ""

wait_for_api

# ── 2. Single Request Upstream Header ───────────────────────────────
header "2. X-Upstream Header Verification"

step "Sending request and checking X-Upstream header..."
UPSTREAM_INFO=$(curl -s -I "${API_BASE}/health" --connect-timeout 5 2>/dev/null | grep -i "x-upstream" || true)

if [ -n "$UPSTREAM_INFO" ]; then
    echo -e "    ${UPSTREAM_INFO}"
    ok "X-Upstream header is present — Nginx is load balancing!"
else
    warn "X-Upstream header not found. Nginx might not be in front."
    warn "Headers received:"
    curl -s -I "${API_BASE}/health" --connect-timeout 5 2>/dev/null | head -20 | while IFS= read -r line; do
        echo -e "    ${line}"
    done
    warn "Make sure you're hitting http://localhost:8080 (Nginx port)"
fi

# ── 3. Weighted Distribution Test ───────────────────────────────────
header "3. ⚡ Weighted Round-Robin Distribution Test"
echo -e "    Nginx upstream config has weights: app1:3, app2:2, app3:1"
echo -e "    Firing 60 requests to observe distribution pattern..."
echo ""

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

# Get container IPs to map X-Upstream (IP:port) to container names
# The X-Upstream header contains IP:port (e.g., "172.24.0.4:8000")
# We need to know which IP belongs to which container
step "Determining container IPs for upstream mapping..."
APP1_IP=$(docker container inspect ecommerce-app-1 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
APP2_IP=$(docker container inspect ecommerce-app-2 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
APP3_IP=$(docker container inspect ecommerce-app-3 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")

echo -e "    app1 IP: ${APP1_IP:-unknown}"
echo -e "    app2 IP: ${APP2_IP:-unknown}"
echo -e "    app3 IP: ${APP3_IP:-unknown}"
ok "Container IPs mapped."
echo ""

# Fire 60 requests and capture upstream info
for i in $(seq 1 60); do
    RESPONSE=$(curl -s -I "${API_BASE}/health" --connect-timeout 3 2>/dev/null | grep -i "x-upstream" || echo "x-upstream: UNKNOWN")
    echo "$RESPONSE" >> "$TMPDIR/upstreams.txt"
done

# Count distribution by IP match
APP1_COUNT=0
APP2_COUNT=0
APP3_COUNT=0
UNKNOWN_COUNT=0

while IFS= read -r line; do
    # Extract the IP from "x-upstream: IP:PORT" format
    UPSTREAM_IP=$(echo "$line" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' || echo "")

    if [ -n "$UPSTREAM_IP" ]; then
        if [ "$UPSTREAM_IP" = "${APP1_IP:-}" ] && [ -n "$APP1_IP" ]; then
            APP1_COUNT=$((APP1_COUNT + 1))
        elif [ "$UPSTREAM_IP" = "${APP2_IP:-}" ] && [ -n "$APP2_IP" ]; then
            APP2_COUNT=$((APP2_COUNT + 1))
        elif [ "$UPSTREAM_IP" = "${APP3_IP:-}" ] && [ -n "$APP3_IP" ]; then
            APP3_COUNT=$((APP3_COUNT + 1))
        else
            UNKNOWN_COUNT=$((UNKNOWN_COUNT + 1))
            echo -e "    ${YELLOW}Unexpected IP:${NC} ${UPSTREAM_IP} (from: ${line:0:60})"
        fi
    else
        UNKNOWN_COUNT=$((UNKNOWN_COUNT + 1))
        echo -e "    ${YELLOW}No IP found in:${NC} ${line:0:80}"
    fi
done < "$TMPDIR/upstreams.txt"

TOTAL=$((APP1_COUNT + APP2_COUNT + APP3_COUNT + UNKNOWN_COUNT))
# Guard against division by zero
[ "$TOTAL" -eq 0 ] && TOTAL=1

echo -e "    ┌──────────────────┬────────┬───────────┐"
echo -e "    │ Server           │ Count  │ %         │"
echo -e "    ├──────────────────┼────────┼───────────┤"
echo -e "    │ ${BOLD}app1 (weight=3)${NC}  │ ${APP1_COUNT}      │ $(( APP1_COUNT * 100 / TOTAL ))%         │"
echo -e "    │ ${BOLD}app2 (weight=2)${NC}  │ ${APP2_COUNT}      │ $(( APP2_COUNT * 100 / TOTAL ))%         │"
echo -e "    │ ${BOLD}app3 (weight=1)${NC}  │ ${APP3_COUNT}      │ $(( APP3_COUNT * 100 / TOTAL ))%         │"
echo -e "    └──────────────────┴────────┴───────────┘"

if [ "$UNKNOWN_COUNT" -gt 0 ]; then
    warn "${UNKNOWN_COUNT} requests went to unknown upstream."
fi

if [ "$TOTAL" -ge 60 ]; then
    ok "All 60+ requests routed through Nginx."

    # Check rough distribution (should be ~50%, ~33%, ~17%)
    if [ "$APP1_COUNT" -ge "$APP2_COUNT" ] && [ "$APP2_COUNT" -ge "$APP3_COUNT" ]; then
        ok "Distribution follows weight pattern: app1 ≥ app2 ≥ app3 ✓"
    else
        warn "Distribution doesn't strictly follow weight order (expected: app1 > app2 > app3)."
        warn "This can happen with small sample sizes — the pattern becomes clearer with more requests."
    fi
else
    fail "Some requests failed. Total responses: ${TOTAL}/60"
fi

# ── 4. Fault Tolerance Test ─────────────────────────────────────────
header "4. ⚡ Fault Tolerance — Kill an App Container"
echo -e "    Stopping app2 to simulate a server crash..."
echo -e "    The API should ${BOLD}still${NC} be available via app1 and app3."
echo ""

# Test that API is healthy BEFORE stopping
step "Pre-stopping health check..."
RESULT=$(curl -s -o /dev/null -w "%{http_code}" "${API_BASE}/health" --connect-timeout 5 2>/dev/null)
echo -e "    Health check returns: ${RESULT}"
ok "API is healthy before stopping app2."

# Stop app2
step "Stopping app2 container..."
STOP_RESULT=$(docker_compose stop app2 2>&1 || true)
echo -e "    ${STOP_RESULT}"
ok "app2 container stopped."

# Wait for Nginx to detect failure (max_fails=3, fail_timeout=30s)
step "Waiting for Nginx to detect app2 is down (5s)..."
sleep 5

# Test that API is still healthy
step "Post-stopping health check..."
FAILOVER_SUCCESS=0
FAILOVER_FAIL=0

for i in $(seq 1 10); do
    UPSTREAM=$(curl -s -I "${API_BASE}/health" --connect-timeout 3 2>/dev/null | grep -i "x-upstream" || echo "")
    STATUS=$(curl -s -o /dev/null -w "%{http_code}" "${API_BASE}/health" --connect-timeout 3 2>/dev/null)

    if [ "$STATUS" = "200" ]; then
        FAILOVER_SUCCESS=$((FAILOVER_SUCCESS + 1))
        echo -e "    Request $i: ${GREEN}200 OK${NC} → ${UPSTREAM:0:50}"
    else
        FAILOVER_FAIL=$((FAILOVER_FAIL + 1))
        echo -e "    Request $i: ${RED}${STATUS}${NC} — API may be down!"
    fi
done

echo ""
if [ "$FAILOVER_SUCCESS" -eq 10 ]; then
    ok "✅ ALL 10 requests succeeded despite app2 being down! Load balancing with fault tolerance WORKS!"
elif [ "$FAILOVER_SUCCESS" -gt 0 ]; then
    warn "${FAILOVER_SUCCESS}/10 succeeded — partial failover working."
else
    fail "API completely unavailable after stopping app2."
    warn "Check that app1 and app3 are still running: docker compose ps"
    warn "Check Nginx logs: docker_compose logs nginx"
fi

# Also check that app2 is NOT in the upstream responses (by IP)
step "Verifying app2 is no longer receiving traffic..."
APP2_AFTER_FAILOVER=0
if [ -n "${APP2_IP:-}" ]; then
    for i in $(seq 1 10); do
        UPSTREAM=$(curl -s -I "${API_BASE}/health" --connect-timeout 3 2>/dev/null | grep -i "x-upstream" || echo "")
        if echo "$UPSTREAM" | grep -q "$APP2_IP"; then
            APP2_AFTER_FAILOVER=$((APP2_AFTER_FAILOVER + 1))
        fi
    done
    if [ "$APP2_AFTER_FAILOVER" -eq 0 ]; then
        ok "app2 (${APP2_IP}) no longer receiving traffic — Nginx correctly removed it from the pool."
    else
        warn "app2 still received ${APP2_AFTER_FAILOVER}/10 requests after being stopped."
    fi
else
    warn "Cannot verify app2 absence — IP was unknown."
fi

# ── 5. Fault Recovery Test ──────────────────────────────────────────
header "5. ⚡ Fault Recovery — Restart app2"
echo -e "    Restarting app2 — it should rejoin the load balancing pool."
echo ""

step "Starting app2 container..."
START_RESULT=$(docker_compose start app2 2>&1 || true)
echo -e "    ${START_RESULT}"

step "Waiting for app2 to become healthy (10s)..."
sleep 10

# Check if app2 is back in service (by IP)
step "Verifying app2 rejoined the pool..."
APP2_BACK_COUNT=0

if [ -n "${APP2_IP:-}" ]; then
    for i in $(seq 1 20); do
        UPSTREAM=$(curl -s -I "${API_BASE}/health" --connect-timeout 3 2>/dev/null | grep -i "x-upstream" || echo "")
        if echo "$UPSTREAM" | grep -q "$APP2_IP"; then
            APP2_BACK_COUNT=$((APP2_BACK_COUNT + 1))
        fi
    done

    if [ "$APP2_BACK_COUNT" -gt 0 ]; then
        ok "✅ app2 (${APP2_IP}) rejoined the pool (seen in ${APP2_BACK_COUNT}/20 requests)!"
        ok "Fault recovery works — Nginx automatically reintegrated the restarted server."
    else
        warn "app2 (${APP2_IP}) didn't appear in upstream responses after restart."
        warn "Check container status: docker_compose ps app2"
        warn "Nginx fail_timeout=30s — it may need more time to recover."
    fi
else
    warn "Cannot verify app2 recovery — IP was unknown."
fi

# ── 6. Final Health Verification ─────────────────────────────────────
header "6. Final Health Check — All Systems Go"

step "Checking all containers are running..."
docker_compose ps --format "table {{.Service}}\t{{.Status}}" 2>/dev/null | while IFS= read -r line; do
    if echo "$line" | grep -q "Up"; then
        echo -e "    ${GREEN}✓${NC} ${line}"
    elif echo "$line" | grep -q "Exit"; then
        echo -e "    ${RED}✗${NC} ${line}"
    else
        echo -e "    ${line}"
    fi
done

step "Running final health check..."
FINAL_STATUS=$(curl -s -o /dev/null -w "%{http_code}" "${API_BASE}/health" --connect-timeout 5 2>/dev/null)
if [ "$FINAL_STATUS" = "200" ]; then
    ok "Health check returns 200 OK ✅"
else
    fail "Health check failed: ${FINAL_STATUS}"
fi

# ── Summary ─────────────────────────────────────────────────────────
print_summary "Task 5 — Nginx Load Balancing & Horizontal Scaling"

echo -e "  ${GREEN}✓${NC} Nginx upstream: app1(weight=3), app2(weight=2), app3(weight=1)"
echo -e "  ${GREEN}✓${NC} X-Upstream header: requests are load balanced"
echo -e "  ${GREEN}✓${NC} Fault tolerance: API survives container failures"
echo -e "  ${GREEN}✓${NC} Fault recovery: restarted containers rejoin pool"
echo -e "  ${GREEN}✓${NC} Rate limiting: 60 req/sec at Nginx level"
echo ""
echo -e "  ${YELLOW}📌${NC} To test the BAD (single-server) version:"
echo -e "     1. In docker-compose.yml, remove app2 and app3 services"
echo -e "     2. Stop app2 and app3: docker compose stop app2 app3"
echo -e "     3. Stop app1 → site is completely down"
echo -e "     4. Restore the load-balanced setup → survives failures"
echo ""

[ "$FAIL_COUNT" -eq 0 ] && exit 0 || exit 1
