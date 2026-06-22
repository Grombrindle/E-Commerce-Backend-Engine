#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
TIMESTAMP=$(date '+%Y%m%d_%H%M%S')
OUTPUT_FILE="/tmp/k6-output-${TIMESTAMP}.txt"
REPORT_FILE="/tmp/k6-report-${TIMESTAMP}.json"
HTML_REPORT_FILE="/tmp/k6-report-${TIMESTAMP}.html"
PROMETHEUS_URL="http://localhost:9090/api/v1/write"
DO_SEED=true

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

if [[ "${1:-}" == "--help" ]]; then
    sed -n '3,/^$/p' "$0" | sed 's/^# //;s/^#$//'
    exit 0
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --skip-seed) DO_SEED=false; shift ;;
        --duration) K6_DURATION="$2"; shift 2 ;;
        *) echo -e "${RED}Unknown option: $1${NC}"; exit 1 ;;
    esac
done

log() { echo -e "${GREEN}[✓]${NC} $1"; }
warn() { echo -e "${YELLOW}[!]${NC} $1"; }
err() { echo -e "${RED}[✗]${NC} $1"; }
header() {
    echo ""
    echo -e "${CYAN}╔══════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${CYAN}║${NC}  ${BOLD}$1${NC}"
    echo -e "${CYAN}╚══════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

header "Step 1 — Checking Docker services"

cd "$PROJECT_ROOT"

if ! docker compose ps 2>/dev/null | grep -q "Up"; then
    err "Docker services are not running!"
    echo "  Start them first:"
    echo "    cd $PROJECT_ROOT && docker compose up -d --build"
    echo "  Then re-run this script."
    exit 1
fi
log "Docker services are running"

header "Step 2 — Seeding race condition test data"

if [ "$DO_SEED" = true ]; then
    if docker compose exec app1 ls /var/www/app/database/seeders/K6TestSeeder.php &>/dev/null; then
        log "K6TestSeeder.php found in container"
    else
        warn "K6TestSeeder.php missing from container — copying it in..."
        docker compose cp database/seeders/K6TestSeeder.php app1:/var/www/app/database/seeders/K6TestSeeder.php
        docker compose exec app1 composer dump-autoload 2>/dev/null
        log "Copied and regenerated autoload"
    fi

    docker compose exec app1 php artisan db:seed --class=K6TestSeeder --force 2>&1 | grep -v "^$"
    log "Race condition test data seeded (Product #1 stock = 3)"
else
    warn "Skipping seed (--skip-seed)"
fi

header "Step 3 — Running k6 load tests"

echo -e "  ${BOLD}Outputs:${NC}"
echo -e "    • Terminal log:   ${CYAN}${OUTPUT_FILE}${NC}"
echo -e "    • JSON report:    ${CYAN}${REPORT_FILE}${NC}"
echo -e "    • Prometheus:     ${CYAN}http://localhost:9090${NC}"
echo -e "    • Grafana:        ${CYAN}http://localhost:3000 (Dashboard → E-Commerce Backend Engine)${NC}"
echo ""
echo -e "  ${YELLOW}Running 100 virtual users across 10 scenarios...${NC}"
echo -e "  ${YELLOW}Results will appear in the Grafana dashboard in real time.${NC}"
echo ""
echo -e "  ${BOLD}Press Ctrl+C to stop early.${NC}"
echo ""

K6_CMD="docker run --rm --network=host \
    -v '${PROJECT_ROOT}/k6:/k6' \
    --user root \
    grafana/k6:latest run \
    --out experimental-prometheus-rw=${PROMETHEUS_URL} \
    --summary-export=/k6/report-k6.json \
    /k6/test-ecommerce.js"

if [[ -n "${K6_DURATION:-}" ]]; then
    K6_CMD="${K6_CMD} --duration ${K6_DURATION}s"
fi

K6_MAIN="${PROJECT_ROOT}/k6/test-ecommerce.js"
if [ ! -f "$K6_MAIN" ]; then
    err "k6 test file not found: $K6_MAIN"
    exit 1
fi

echo "────────────────────────────────────────────────────────────────"
eval "$K6_CMD" 2>&1 | tee "$OUTPUT_FILE" || true
EXIT_CODE=${PIPESTATUS[0]}

if [ -f "${PROJECT_ROOT}/k6/report-k6.json" ]; then
    cp "${PROJECT_ROOT}/k6/report-k6.json" "$REPORT_FILE"
    rm -f "${PROJECT_ROOT}/k6/report-k6.json"
fi

echo "────────────────────────────────────────────────────────────────"

header "Step 4 — Results"

if [ $EXIT_CODE -eq 0 ]; then
    log "${BOLD}k6 test completed successfully!${NC}"
else
    warn "k6 test finished with exit code $EXIT_CODE (thresholds may have failed)"
fi

echo ""
header "Step 5 — Generating HTML report"

if [ -f "$REPORT_FILE" ]; then
    bash "${SCRIPT_DIR}/generate-k6-html-report.sh" "$REPORT_FILE" "$HTML_REPORT_FILE"
    log "HTML report generated"
else
    warn "No JSON report found at $REPORT_FILE — skipping HTML generation"
fi

echo ""
echo -e "  ${BOLD}Files saved:${NC}"
echo -e "    • Terminal output:  ${CYAN}${OUTPUT_FILE}${NC}"
echo -e "    • JSON report:      ${CYAN}${REPORT_FILE}${NC}"
echo -e "    • HTML report:      ${CYAN}${HTML_REPORT_FILE}${NC}"
echo ""
echo -e "  ${BOLD}To view the Grafana dashboard:${NC}"
echo -e "    Open: ${CYAN}http://localhost:3000${NC} (login: admin / admin)"
echo -e "    Navigate to: Dashboards → E-Commerce Backend Engine"
echo ""
echo -e "  ${BOLD}Quick view of results:${NC}"
echo -e "    ${GREEN}cat \"${OUTPUT_FILE}\"${NC}"
echo ""

exit $EXIT_CODE
