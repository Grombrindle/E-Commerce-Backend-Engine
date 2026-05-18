#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
# run_all.sh — E-Commerce API Test Suite Orchestrator
#
# Runs all 5 task test scripts sequentially and provides a summary.
#
# Usage:
#   ./run_all.sh                    # Run all tests
#   ./run_all.sh --skip=5           # Skip Task 5 (load balancing)
#   ./run_all.sh --only=1,3         # Only Tasks 1 and 3
#   ./run_all.sh --help             # Show this help
#
# Environment:
#   API_BASE    - Base URL (default: http://localhost:8080/api/v1)
#   TIMEOUT     - curl timeout in seconds (default: 10)
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

# ── Colors ────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ── Parse Arguments ──────────────────────────────────────────────────
SHOW_HELP=false
SKIP_LIST=()
ONLY_LIST=()

for arg in "$@"; do
    case "$arg" in
        --help|-h)
            SHOW_HELP=true
            ;;
        --skip=*)
            IFS=',' read -ra NUMS <<< "${arg#*=}"
            for n in "${NUMS[@]}"; do
                SKIP_LIST+=("$n")
            done
            ;;
        --only=*)
            IFS=',' read -ra NUMS <<< "${arg#*=}"
            for n in "${NUMS[@]}"; do
                ONLY_LIST+=("$n")
            done
            ;;
        *)
            echo -e "${RED}Unknown argument: ${arg}${NC}"
            echo "Usage: $0 [--skip=1,3,5] [--only=2,4] [--help]"
            exit 1
            ;;
    esac
done

if [ "$SHOW_HELP" = true ]; then
    echo ""
    echo -e "${BOLD}E-Commerce API — Test Suite Orchestrator${NC}"
    echo ""
    echo "Usage:"
    echo "  ./run_all.sh                    Run all 5 task tests"
    echo "  ./run_all.sh --skip=5           Skip Task 5"
    echo "  ./run_all.sh --skip=4,5         Skip Tasks 4 and 5"
    echo "  ./run_all.sh --only=1,3         Only Tasks 1 and 3"
    echo ""
    echo "Available Tasks:"
    echo "  1 - Race Condition & Data Integrity (Pessimistic Locking)"
    echo "  2 - Rate Limiting & Capacity Control (Throttling)"
    echo "  3 - Asynchronous Queues (Job Dispatch)"
    echo "  4 - Chunked Batch Processing (Memory Safety)"
    echo "  5 - Nginx Load Balancing & Horizontal Scaling"
    echo ""
    echo "Environment:"
    echo "  API_BASE    - Base URL (default: ${API_BASE:-http://localhost:8080/api/v1})"
    echo "  TIMEOUT     - curl timeout (default: ${TIMEOUT:-10}s)"
    echo ""
    exit 0
fi

should_run() {
    local task_num="$1"

    # If --only is specified, only run tasks in the list
    if [ "${#ONLY_LIST[@]}" -gt 0 ]; then
        for n in "${ONLY_LIST[@]}"; do
            [ "$n" = "$task_num" ] && return 0
        done
        return 1
    fi

    # If --skip is specified, skip tasks in the list
    for n in "${SKIP_LIST[@]}"; do
        [ "$n" = "$task_num" ] && return 1
    done

    return 0
}

# ── Script Registry ──────────────────────────────────────────────────
declare -A TASKS
TASKS[1]="test_task1_race_condition.sh"
TASKS[2]="test_task2_rate_limiting.sh"
TASKS[3]="test_task3_async_queues.sh"
TASKS[4]="test_task4_batch_processing.sh"
TASKS[5]="test_task5_load_distribution.sh"

declare -A TASK_NAMES
TASK_NAMES[1]="Race Condition & Data Integrity"
TASK_NAMES[2]="Rate Limiting & Capacity Control"
TASK_NAMES[3]="Asynchronous Queues"
TASK_NAMES[4]="Chunked Batch Processing"
TASK_NAMES[5]="Nginx Load Balancing & Horizontal Scaling"

# ── Print Banner ─────────────────────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║${NC}  ${BOLD}E-Commerce Backend Engine — Parallel Programming Test Suite${NC}      ${CYAN}║${NC}"
echo -e "${CYAN}║${NC}  Running against: ${API_BASE:-http://localhost:8080/api/v1}                       ${CYAN}║${NC}"
echo -e "${CYAN}║${NC}  $(date)                    ${CYAN}║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════════════════════════╝${NC}"
echo ""

# ── Run Tasks ────────────────────────────────────────────────────────
OVERALL_PASS=0
OVERALL_FAIL=0
OVERALL_TOTAL=0
FAILED_TASKS=()

for i in 1 2 3 4 5; do
    if should_run "$i"; then
        SCRIPT="${SCRIPT_DIR}/${TASKS[$i]}"
        NAME="${TASK_NAMES[$i]}"

        if [ ! -f "$SCRIPT" ]; then
            echo -e "${RED}Script not found: ${SCRIPT}${NC}"
            FAILED_TASKS+=("$i")
            continue
        fi

        echo ""
        echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
        echo -e "${BOLD}  RUNNING TASK $i — ${NAME}${NC}"
        echo -e "${CYAN}══════════════════════════════════════════════════════════════════════${NC}"
        echo ""

        set +e
        START_TIME=$(date +%s)
        bash "$SCRIPT"
        EXIT_CODE=$?
        END_TIME=$(date +%s)
        DURATION=$((END_TIME - START_TIME))
        set -euo pipefail

        # Parse pass/fail from the script's output (last lines)
        # Each script uses the print_summary function which outputs "Passed: X  |  Failed: Y"

        if [ "$EXIT_CODE" -eq 0 ]; then
            echo -e "${GREEN}✅ Task $i completed successfully in ${DURATION}s${NC}"
        else
            echo -e "${RED}❌ Task $i FAILED (exit code: ${EXIT_CODE}) in ${DURATION}s${NC}"
            FAILED_TASKS+=("$i")
        fi
        echo ""
    else
        echo -e "${YELLOW}⏭️  Skipping Task $i — ${TASK_NAMES[$i]}${NC}"
    fi
done

# ── Overall Summary ─────────────────────────────────────────────────
echo ""
echo -e "${CYAN}╔══════════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${CYAN}║${NC}  ${BOLD}TEST SUITE SUMMARY${NC}                                              ${CYAN}║${NC}"
echo -e "${CYAN}╚══════════════════════════════════════════════════════════════════════╝${NC}"
echo ""

for i in 1 2 3 4 5; do
    STATUS=""
    if ! should_run "$i"; then
        STATUS="${YELLOW}⏭️  SKIPPED${NC}"
    elif [[ " ${FAILED_TASKS[*]} " =~ " ${i} " ]]; then
        STATUS="${RED}❌  FAILED${NC}"
    else
        STATUS="${GREEN}✅  PASSED${NC}"
    fi
    echo -e "  Task ${i}: ${STATUS}  — ${TASK_NAMES[$i]}"
done

echo ""
if [ "${#FAILED_TASKS[@]}" -eq 0 ]; then
    echo -e "  ${GREEN}${BOLD}🎉 ALL TASKS PASSED!${NC}"
    echo ""
    echo -e "  ${YELLOW}📌${NC} If any test results need attention:"
    echo -e "      - Check individual script outputs above"
    echo -e "      - Review logs: docker compose logs app1"
    echo -e "      - Review the TESTING_GUIDE.md for manual Postman testing"
else
    echo -e "  ${RED}${BOLD}⚠️  ${#FAILED_TASKS[@]} TASK(S) FAILED: ${FAILED_TASKS[*]}${NC}"
    echo ""
    echo -e "  ${YELLOW}Troubleshooting:${NC}"
    echo -e "      - Check the output above for specific error messages"
    echo -e "      - Ensure Docker containers are running: docker compose ps"
    echo -e "      - Run the failed task alone: ./scripts/test_task${FAILED_TASKS[0]}_*.sh"
    echo -e "      - Check Laravel logs: docker compose exec app1 tail -f storage/logs/laravel.log"
fi
echo ""

if [ "${#FAILED_TASKS[@]}" -eq 0 ]; then
    exit 0
else
    exit 1
fi
