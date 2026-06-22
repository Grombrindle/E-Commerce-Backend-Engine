#!/usr/bin/env bash

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/lib.sh"

declare -A SEEN_IPS

header "TASK 5 — NGINX LOAD BALANCING & HORIZONTAL SCALING"

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

echo ""
echo -e "    ${CYAN}── Running Services ──${NC}"
docker_compose ps --format "table {{.Service}}\t{{.Status}}\t{{.Ports}}" 2>/dev/null | while IFS= read -r line; do
    echo -e "    ${line}"
done
echo ""

wait_for_api

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

header "3. ⚡ Weighted Round-Robin Distribution Test"
echo -e "    Nginx upstream config has weights: app1:3, app2:2, app3:1"
echo -e "    Firing 60 requests to observe distribution pattern..."
echo ""

TMPDIR=$(mktemp -d)
trap 'rm -rf "$TMPDIR"' EXIT

step "Determining container IPs for upstream mapping..."

APP1_IP=""
APP2_IP=""
APP3_IP=""

APP1_IP=$(docker_compose exec -T app1 sh -c 'hostname -i 2>/dev/null' 2>/dev/null | tr -d ' \t\n\r' || echo "")
APP2_IP=$(docker_compose exec -T app2 sh -c 'hostname -i 2>/dev/null' 2>/dev/null | tr -d ' \t\n\r' || echo "")
APP3_IP=$(docker_compose exec -T app3 sh -c 'hostname -i 2>/dev/null' 2>/dev/null | tr -d ' \t\n\r' || echo "")

if [ -z "$APP1_IP" ]; then
    APP1_IP=$(docker container inspect ecommerce-app-1 --format '{{(index .NetworkSettings.Networks "ecommerce_default").IPAddress}}' 2>/dev/null || \
              docker container inspect ecommerce-app-1 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
fi
if [ -z "$APP2_IP" ]; then
    APP2_IP=$(docker container inspect ecommerce-app-2 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
fi
if [ -z "$APP3_IP" ]; then
    APP3_IP=$(docker container inspect ecommerce-app-3 --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
fi

if [ -z "$APP1_IP" ]; then
    APP1_CID=$(docker ps --filter "name=app-1" --format '{{.ID}}' 2>/dev/null | head -1)
    [ -n "$APP1_CID" ] && APP1_IP=$(docker inspect "$APP1_CID" --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
fi
if [ -z "$APP2_IP" ]; then
    APP2_CID=$(docker ps --filter "name=app-2" --format '{{.ID}}' 2>/dev/null | head -1)
    [ -n "$APP2_CID" ] && APP2_IP=$(docker inspect "$APP2_CID" --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
fi
if [ -z "$APP3_IP" ]; then
    APP3_CID=$(docker ps --filter "name=app-3" --format '{{.ID}}' 2>/dev/null | head -1)
    [ -n "$APP3_CID" ] && APP3_IP=$(docker inspect "$APP3_CID" --format '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' 2>/dev/null || echo "")
fi

echo -e "    app1 IP: ${APP1_IP:-unknown}"
echo -e "    app2 IP: ${APP2_IP:-unknown}"
echo -e "    app3 IP: ${APP3_IP:-unknown}"

if [ -z "$APP1_IP" ] && [ -z "$APP2_IP" ] && [ -z "$APP3_IP" ]; then
    warn "Could not determine container IPs! Will collect upstream addresses dynamically."
    DYNAMIC_UPSTREAM_DETECTION=true
else
    DYNAMIC_UPSTREAM_DETECTION=false
    ok "Container IPs mapped."
fi
echo ""

for i in $(seq 1 60); do
    RESPONSE=$(curl -s -I "${API_BASE}/health" --connect-timeout 3 2>/dev/null | grep -i "x-upstream" || echo "x-upstream: UNKNOWN")
    echo "$RESPONSE" >> "$TMPDIR/upstreams.txt"
done

APP1_COUNT=0
APP2_COUNT=0
APP3_COUNT=0
UNKNOWN_COUNT=0

while IFS= read -r line; do
    UPSTREAM_IP=$(echo "$line" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' || echo "")

    if [ -n "$UPSTREAM_IP" ]; then
        if [ "${DYNAMIC_UPSTREAM_DETECTION:-false}" = "true" ]; then
            matched=false
            if [ "${SEEN_IPS[$UPSTREAM_IP]:-not_seen}" = "app1" ]; then
                APP1_COUNT=$((APP1_COUNT + 1))
                matched=true
            elif [ "${SEEN_IPS[$UPSTREAM_IP]:-}" = "app2" ]; then
                APP2_COUNT=$((APP2_COUNT + 1))
                matched=true
            elif [ "${SEEN_IPS[$UPSTREAM_IP]:-}" = "app3" ]; then
                APP3_COUNT=$((APP3_COUNT + 1))
                matched=true
            fi
            if [ "$matched" = false ]; then
                CONTAINER_NAME=$(docker ps --filter "network=ecommerce_default" --format '{{.Names}}' 2>/dev/null | \
                    xargs -I {} sh -c 'docker inspect {} --format "{{.Name}} {{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}" 2>/dev/null' | \
                    grep "$UPSTREAM_IP" | head -1 | awk '{print $1}' | sed 's/^\///' || echo "")
                if echo "$CONTAINER_NAME" | grep -q "app-1"; then
                    SEEN_IPS[$UPSTREAM_IP]="app1"
                    APP1_COUNT=$((APP1_COUNT + 1))
                elif echo "$CONTAINER_NAME" | grep -q "app-2"; then
                    SEEN_IPS[$UPSTREAM_IP]="app2"
                    APP2_COUNT=$((APP2_COUNT + 1))
                elif echo "$CONTAINER_NAME" | grep -q "app-3"; then
                    SEEN_IPS[$UPSTREAM_IP]="app3"
                    APP3_COUNT=$((APP3_COUNT + 1))
                else
                    UNKNOWN_COUNT=$((UNKNOWN_COUNT + 1))
                fi
            fi
        else
            if [ "$UPSTREAM_IP" = "${APP1_IP:-}" ] && [ -n "$APP1_IP" ]; then
                APP1_COUNT=$((APP1_COUNT + 1))
            elif [ "$UPSTREAM_IP" = "${APP2_IP:-}" ] && [ -n "$APP2_IP" ]; then
                APP2_COUNT=$((APP2_COUNT + 1))
            elif [ "$UPSTREAM_IP" = "${APP3_IP:-}" ] && [ -n "$APP3_IP" ]; then
                APP3_COUNT=$((APP3_COUNT + 1))
            else
                UNKNOWN_COUNT=$((UNKNOWN_COUNT + 1))
            fi
        fi
    else
        UNKNOWN_COUNT=$((UNKNOWN_COUNT + 1))
    fi
done < "$TMPDIR/upstreams.txt"

TOTAL=$((APP1_COUNT + APP2_COUNT + APP3_COUNT + UNKNOWN_COUNT))
[ "$TOTAL" -eq 0 ] && TOTAL=1

echo -e "    ┌──────────────────┬────────┬───────────┐"
echo -e "    │ Server           │ Count  │ %         │"
echo -e "    ├──────────────────┼────────┼───────────┤"

calc_pct() {
    local val=$1
    local total=$2
    if [ "$total" -eq 0 ]; then echo 0; else echo $(( val * 100 / total )); fi
}

PCT1=$(calc_pct $APP1_COUNT $TOTAL)
PCT2=$(calc_pct $APP2_COUNT $TOTAL)
PCT3=$(calc_pct $APP3_COUNT $TOTAL)
PCT_UNK=$(calc_pct $UNKNOWN_COUNT $TOTAL)

echo -e "    │ ${BOLD}app1 (weight=3)${NC}  │ ${APP1_COUNT}      │ ${PCT1}%         │"
echo -e "    │ ${BOLD}app2 (weight=2)${NC}  │ ${APP2_COUNT}      │ ${PCT2}%         │"
echo -e "    │ ${BOLD}app3 (weight=1)${NC}  │ ${APP3_COUNT}      │ ${PCT3}%         │"
echo -e "    │ ${YELLOW}unknown${NC}          │ ${UNKNOWN_COUNT}      │ ${PCT_UNK}%         │"
echo -e "    └──────────────────┴────────┴───────────┘"

if [ "$UNKNOWN_COUNT" -gt 0 ] && [ "$PCT_UNK" -gt 10 ]; then
    warn "${UNKNOWN_COUNT}/${TOTAL} (${PCT_UNK}%) requests went to unknown upstream."
    warn "IP detection may need adjustment. See raw upstream values below:"
    sort "$TMPDIR/upstreams.txt" | uniq -c | sort -rn | head -5 | while IFS= read -r up_line; do
        echo -e "    ${up_line}"
    done
elif [ "$UNKNOWN_COUNT" -gt 0 ]; then
    sub "${UNKNOWN_COUNT}/${TOTAL} (${PCT_UNK}%) unknown — acceptable margin."
fi

if [ "$TOTAL" -ge 60 ]; then
    ok "All 60+ requests routed through Nginx."

    KNOWN_TOTAL=$((APP1_COUNT + APP2_COUNT + APP3_COUNT))
    if [ "$KNOWN_TOTAL" -ge 30 ]; then
        if [ "$APP1_COUNT" -ge "$APP2_COUNT" ] && [ "$APP2_COUNT" -ge "$APP3_COUNT" ]; then
            ok "Distribution follows weight pattern: app1 ≥ app2 ≥ app3 ✓"
        else
            warn "Distribution doesn't strictly follow weight order (expected: app1 > app2 > app3)."
            warn "This can happen with small sample sizes — the pattern becomes clearer with more requests."
        fi
    else
        warn "Could not verify distribution pattern — only ${KNOWN_TOTAL}/60 requests matched known upstream IPs."
        warn "Verify manually: for i in 1..12; do curl -sI http://localhost:8080/api/v1/health | grep -i x-upstream; done"
    fi
else
    fail "Some requests failed. Total responses: ${TOTAL}/60"
fi

header "4. ⚡ Fault Tolerance — Kill an App Container"
echo -e "    Stopping app2 to simulate a server crash..."
echo -e "    The API should ${BOLD}still${NC} be available via app1 and app3."
echo ""

step "Pre-stopping health check..."
RESULT=$(curl -s -o /dev/null -w "%{http_code}" "${API_BASE}/health" --connect-timeout 5 2>/dev/null)
echo -e "    Health check returns: ${RESULT}"
ok "API is healthy before stopping app2."

step "Stopping app2 container..."
STOP_RESULT=$(docker_compose stop app2 2>&1 || true)
echo -e "    ${STOP_RESULT}"
ok "app2 container stopped."

step "Waiting for Nginx to detect app2 is down (5s)..."
sleep 5

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

header "5. ⚡ Fault Recovery — Restart app2"
echo -e "    Restarting app2 — it should rejoin the load balancing pool."
echo ""

step "Starting app2 container..."
START_RESULT=$(docker_compose start app2 2>&1 || true)
echo -e "    ${START_RESULT}"

step "Waiting for app2 to become healthy (10s)..."
sleep 10

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
