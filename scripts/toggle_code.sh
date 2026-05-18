#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════
# toggle_code.sh — Switch between OLD (bad) and NEW (good) code per task
# ═══════════════════════════════════════════════════════════════════════
#
# Usage:
#   ./scripts/toggle_code.sh <task> <mode>
#
# Tasks:
#   1  — Race Condition (OrderService — DB::transaction + lockForUpdate)
#   2  — Rate Limiting (AppServiceProvider — NO uncommitted changes, old
#                       code only in comments — no toggle needed)
#   3  — Async Queues (OrderService — dispatch vs sync calls)
#   4  — Batch Processing (DispatchDailySalesBatchJob — NO uncommitted
#                          changes, old code only in comments)
#   5  — Load Balancing (docker-compose.yml — multi vs single instance)
#   all — All tasks with toggleable code
#   cart — Cart reservation system (CartService, Product, controllers)
#
# Modes:
#   old   — Switch to the OLD/BAD version
#   new   — Switch to the NEW/GOOD version
#   show  — Show current status of all tracked files
#
# Examples:
#   ./scripts/toggle_code.sh 1 old    # Switch Task 1 to OLD code
#   ./scripts/toggle_code.sh cart new  # Switch cart to NEW code
#   ./scripts/toggle_code.sh all show  # Show status of all files
#
# ═══════════════════════════════════════════════════════════════════════

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR%/scripts}"
[ "$PROJECT_ROOT" = "$SCRIPT_DIR" ] && PROJECT_ROOT="$SCRIPT_DIR/.."

PATCH_DIR="/tmp/ecommerce_patches"
BACKUP_PATCH="$PATCH_DIR/full_new_code.patch"
mkdir -p "$PATCH_DIR"

# ── Colors ────────────────────────────────────────────────────────────
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
NC='\033[0m'

# ── Task File Definitions ─────────────────────────────────────────────
# Each task maps to specific files. Toggling old = git checkout HEAD for
# those files. Toggling new = re-apply the saved patch for those files.

declare -A TASK_FILES
TASK_FILES[1]="app/Services/OrderService.php"
TASK_FILES[2]="app/Providers/AppServiceProvider.php"
TASK_FILES[3]="app/Services/OrderService.php"
TASK_FILES[4]="app/Jobs/DispatchDailySalesBatchJob.php"
TASK_FILES[5]="docker-compose.yml deploy/nginx/load-balancer.conf"
TASK_FILES[all]="app/Services/OrderService.php app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php docker-compose.yml deploy/nginx/load-balancer.conf"
TASK_FILES[cart]="app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php"

# ── Task Has Patches? (tasks 2 and 4 have no uncommitted changes) ────
HAS_PATCH=([1]=true [2]=false [3]=true [4]=false [5]=true [all]=true [cart]=true)

# ── Description for each task ────────────────────────────────────────
declare -A TASK_DESC
TASK_DESC[1]="Race Condition — OrderService: DB::transaction + lockForUpdate"
TASK_DESC[2]="Rate Limiting — AppServiceProvider: RateLimiter::for() (no toggle needed — already committed)"
TASK_DESC[3]="Async Queues — OrderService: dispatch() vs synchronous calls"
TASK_DESC[4]="Batch Processing — DispatchDailySalesBatchJob: chunk+Bus::batch (no toggle needed — already committed)"
TASK_DESC[5]="Load Balancing — docker-compose.yml: 3 apps+Nginx vs single instance"
TASK_DESC[cart]="Cart Reservation — CartService, Product, Controllers: reservation + cache flush"
TASK_DESC[all]="ALL toggleable code"

# ═══════════════════════════════════════════════════════════════════════
#  Helper Functions
# ═══════════════════════════════════════════════════════════════════════

# Save the full git diff as a backup patch (idempotent — only saves once)
save_patches() {
    cd "$PROJECT_ROOT"

    # Always re-generate patches from the current git diff
    git diff -- app/Services/OrderService.php > "$PATCH_DIR/task1.patch" 2>/dev/null
    git diff -- app/Services/OrderService.php > "$PATCH_DIR/task3.patch" 2>/dev/null
    git diff -- docker-compose.yml deploy/nginx/load-balancer.conf > "$PATCH_DIR/task5.patch" 2>/dev/null
    git diff -- app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php > "$PATCH_DIR/task_cart.patch" 2>/dev/null

    # Full combined patch
    cat "$PATCH_DIR"/task*.patch 2>/dev/null > "$BACKUP_PATCH" || true
}

# Check if the working tree is clean (no uncommitted changes)
is_clean() {
    cd "$PROJECT_ROOT"
    git diff --quiet 2>/dev/null
}

# ── Toggle ────────────────────────────────────────────────────────────

toggle_task() {
    local task="$1"
    local mode="$2"

    cd "$PROJECT_ROOT"

    # Save patches if not already saved
    save_patches

    # Resolve task name
    local task_num
    case "$task" in
        1|task1|"Task 1") task_num=1 ;;
        2|task2|"Task 2") task_num=2 ;;
        3|task3|"Task 3") task_num=3 ;;
        4|task4|"Task 4") task_num=4 ;;
        5|task5|"Task 5") task_num=5 ;;
        cart|reservation|Cart) task_num=cart ;;
        all|All|ALL) task_num=all ;;
        *)
            echo -e "${RED}Unknown task: $task${NC}"
            echo "Valid: 1, 2, 3, 4, 5, cart, all"
            exit 1
            ;;
    esac

    local desc="${TASK_DESC[$task_num]}"
    local files="${TASK_FILES[$task_num]}"
    local has_patch="${HAS_PATCH[$task_num]}"

    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e " Task ${task_num}: ${desc}"
    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"

    case "$mode" in
        old|OLD)
            if [ "$has_patch" = false ]; then
                echo -e "  ${YELLOW}⚠ This task has no toggleable code changes.${NC}"
                echo -e "    The 'bad' code is only documented in comments in the file."
                echo -e "    Actual source already has the 'good' implementation.\n"
                return 0
            fi
            echo -e "  Switching ${YELLOW}→ OLD${NC} code for: ${files}"
            git checkout HEAD -- $files 2>/dev/null || true
            echo -e "  ${GREEN}✓${NC} OLD code restored. Run 'git diff -- $files' to see the change.\n"
            ;;
        new|NEW)
            if [ "$has_patch" = false ]; then
                echo -e "  ${GREEN}✓ Already on NEW code (no toggleable changes).${NC}\n"
                return 0
            fi
            echo -e "  Switching ${GREEN}→ NEW${NC} code for: ${files}"
            # First revert to old (HEAD), then apply the saved patch
            git checkout HEAD -- $files 2>/dev/null || true
            local patch_file="$PATCH_DIR/task${task_num}.patch"
            [ "$task_num" = "cart" ] && patch_file="$PATCH_DIR/task_cart.patch"
            [ "$task_num" = "all" ] && patch_file="$BACKUP_PATCH"

            if [ -f "$patch_file" ] && [ -s "$patch_file" ]; then
                git apply "$patch_file" 2>/dev/null || {
                    echo -e "  ${RED}✗ Failed to apply patch.${NC}"
                    echo -e "    The working tree may have conflicts."
                    echo -e "    Restore with: git checkout HEAD -- $files"
                    return 1
                }
                echo -e "  ${GREEN}✓${NC} NEW code applied.\n"
            else
                echo -e "  ${YELLOW}⚠ No saved patch found for this task.${NC}"
                echo -e "    Run 'git diff' when the NEW code is active to generate patches.\n"
            fi
            ;;
        show|status)
            echo -e "  Files: ${files}\n"
            for f in $files; do
                if git diff --quiet -- "$f" 2>/dev/null; then
                    echo -e "    ${GREEN}✓${NC} $f — matches HEAD (OLD code if no changes, or NEW code already committed)"
                else
                    echo -e "    ${YELLOW}⚠${NC} $f — has uncommitted changes (NEW code active)"
                fi
            done
            echo ""
            ;;
        *)
            echo -e "${RED}Unknown mode: $mode. Use: old, new, or show${NC}"
            exit 1
            ;;
    esac
}

# ═══════════════════════════════════════════════════════════════════════
#  Main
# ═══════════════════════════════════════════════════════════════════════

# Show help if no args
if [ $# -lt 1 ] || [ "$1" = "--help" ] || [ "$1" = "-h" ]; then
    echo ""
    echo -e "${BOLD}Usage:${NC} ./scripts/toggle_code.sh <task> <mode>"
    echo ""
    echo -e "${BOLD}Tasks:${NC}"
    echo -e "  1     — Race Condition (OrderService)"
    echo -e "  2     — Rate Limiting (AppServiceProvider — no toggle, already committed)"
    echo -e "  3     — Async Queues (OrderService)"
    echo -e "  4     — Batch Processing (DispatchDailySalesBatchJob — no toggle, already committed)"
    echo -e "  5     — Load Balancing (docker-compose.yml)"
    echo -e "  cart  — Cart Reservation (CartService, Product, controllers)"
    echo -e "  all   — All toggleable tasks"
    echo ""
    echo -e "${BOLD}Modes:${NC}"
    echo -e "  old   — Switch to OLD/BAD version"
    echo -e "  new   — Switch to NEW/GOOD version"
    echo -e "  show  — Show current status of tracked files"
    echo ""
    echo -e "${BOLD}Examples:${NC}"
    echo -e "  ./scripts/toggle_code.sh 1 old    # Switch Task 1 to OLD code"
    echo -e "  ./scripts/toggle_code.sh cart new  # Switch cart to NEW code"
    echo -e "  ./scripts/toggle_code.sh all show  # Show status"
    echo ""
    exit 0
fi

toggle_task "$1" "${2:-show}"
