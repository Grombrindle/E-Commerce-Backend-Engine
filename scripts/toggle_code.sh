#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR%/scripts}"
[ "$PROJECT_ROOT" = "$SCRIPT_DIR" ] && PROJECT_ROOT="$SCRIPT_DIR/.."

PATCH_DIR="/tmp/ecommerce_patches"
BACKUP_PATCH="$PATCH_DIR/full_new_code.patch"
mkdir -p "$PATCH_DIR"

RED='\033[0;31m'; GREEN='\033[0;32m'; YELLOW='\033[1;33m'
CYAN='\033[0;36m'; BOLD='\033[1m'; NC='\033[0m'


declare -A TASK_FILES
TASK_FILES[1]="app/Services/OrderService.php"
TASK_FILES[2]="app/Providers/AppServiceProvider.php"
TASK_FILES[3]="app/Services/OrderService.php"
TASK_FILES[4]="app/Jobs/DispatchDailySalesBatchJob.php"
TASK_FILES[5]="docker-compose.yml deploy/nginx/load-balancer.conf"
TASK_FILES[6]="app/Http/Controllers/API/ProductController.php"
TASK_FILES[7]="app/Services/OptimisticInventoryService.php"
TASK_FILES[8]="app/Services/CheckoutService.php"
TASK_FILES[all]="app/Services/OrderService.php app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php app/Services/OptimisticInventoryService.php app/Services/CheckoutService.php docker-compose.yml deploy/nginx/load-balancer.conf"
TASK_FILES[cart]="app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php"

declare -A HAS_PATCH
HAS_PATCH=([1]=true [2]=false [3]=true [4]=false [5]=true [6]=true [7]=true [8]=true [all]=true [cart]=true)

declare -A NEW_FILE_TASK
NEW_FILE_TASK[7]=true
NEW_FILE_TASK[8]=true

PATCH_FILE() {
    case "$1" in
        1|3)   echo "$PATCH_DIR/task1.patch" ;;
        5)     echo "$PATCH_DIR/task5.patch" ;;
        6)     echo "$PATCH_DIR/task6.patch" ;;
        cart)  echo "$PATCH_DIR/task_cart.patch" ;;
        all)   echo "$BACKUP_PATCH" ;;
        *)     echo "" ;;
    esac
}

declare -A TASK_DESC
TASK_DESC[1]="Race Condition — OrderService: DB::transaction + lockForUpdate"
TASK_DESC[2]="Rate Limiting — AppServiceProvider (already committed — no toggle)"
TASK_DESC[3]="Async Queues — OrderService: dispatch() vs synchronous calls"
TASK_DESC[4]="Batch Processing — DispatchDailySalesBatchJob (already committed — no toggle)"
TASK_DESC[5]="Load Balancing — docker-compose.yml: 3 apps+Nginx vs single instance"
TASK_DESC[6]="Caching — ProductController: CachedProductService vs direct DB queries"
TASK_DESC[7]="Concurrency — OptimisticInventoryService: version lock vs last-writer-wins"
TASK_DESC[8]="ACID Checkout — CheckoutService: DB::transaction vs two-step non-atomic"
TASK_DESC[all]="ALL toggleable code"
TASK_DESC[cart]="Cart Reservation — CartService, Product, Controllers: reservation + cache flush"


save_patches() {
    cd "$PROJECT_ROOT"

    local did_save=false

    if [ ! -f "$PATCH_DIR/task1.patch" ] || [ ! -s "$PATCH_DIR/task1.patch" ]; then
        git diff -- app/Services/OrderService.php > "$PATCH_DIR/task1.patch" 2>/dev/null
        ln -sf "task1.patch" "$PATCH_DIR/task3.patch" 2>/dev/null || cp "$PATCH_DIR/task1.patch" "$PATCH_DIR/task3.patch" 2>/dev/null
        did_save=true
    fi

    if [ ! -f "$PATCH_DIR/task_cart.patch" ] || [ ! -s "$PATCH_DIR/task_cart.patch" ]; then
        git diff -- app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php > "$PATCH_DIR/task_cart.patch" 2>/dev/null
        did_save=true
    fi

    if [ ! -f "$PATCH_DIR/task5.patch" ] || [ ! -s "$PATCH_DIR/task5.patch" ]; then
        git diff -- docker-compose.yml deploy/nginx/load-balancer.conf > "$PATCH_DIR/task5.patch" 2>/dev/null
        did_save=true
    fi

    if [ ! -f "$PATCH_DIR/task6.patch" ] || [ ! -s "$PATCH_DIR/task6.patch" ]; then
        git diff -- app/Http/Controllers/API/ProductController.php > "$PATCH_DIR/task6.patch" 2>/dev/null
        did_save=true
    fi

    for f in "$PATCH_DIR"/task1.patch "$PATCH_DIR"/task5.patch "$PATCH_DIR"/task6.patch "$PATCH_DIR"/task_cart.patch; do
        [ -s "$f" ] && cat "$f" >> "$BACKUP_PATCH" 2>/dev/null || true
    done

    $did_save && echo -e "  ${GREEN}✓${NC} Patches saved to $PATCH_DIR" || true
}

force_save_patches() {
    cd "$PROJECT_ROOT"
    git diff -- app/Services/OrderService.php > "$PATCH_DIR/task1.patch" 2>/dev/null
    ln -sf "task1.patch" "$PATCH_DIR/task3.patch" 2>/dev/null || cp "$PATCH_DIR/task1.patch" "$PATCH_DIR/task3.patch" 2>/dev/null
    git diff -- app/Services/CartService.php app/Models/Product.php app/Http/Controllers/API/CartController.php app/Http/Controllers/API/OrderController.php app/Http/Controllers/API/ProductController.php > "$PATCH_DIR/task_cart.patch" 2>/dev/null
    git diff -- docker-compose.yml deploy/nginx/load-balancer.conf > "$PATCH_DIR/task5.patch" 2>/dev/null
    git diff -- app/Http/Controllers/API/ProductController.php > "$PATCH_DIR/task6.patch" 2>/dev/null
    > "$BACKUP_PATCH"
    for f in "$PATCH_DIR"/task1.patch "$PATCH_DIR"/task5.patch "$PATCH_DIR"/task6.patch "$PATCH_DIR"/task_cart.patch; do
        [ -s "$f" ] && cat "$f" >> "$BACKUP_PATCH" 2>/dev/null || true
    done
    echo -e "  ${GREEN}✓${NC} All patches re-saved from current state."
}

resolve_task() {
    case "$1" in
        1|task1|"Task 1") echo "1" ;;
        2|task2|"Task 2") echo "2" ;;
        3|task3|"Task 3") echo "3" ;;
        4|task4|"Task 4") echo "4" ;;
        5|task5|"Task 5") echo "5" ;;
        6|task6|"Task 6") echo "6" ;;
        7|task7|"Task 7") echo "7" ;;
        8|task8|"Task 8") echo "8" ;;
        cart|reservation|Cart) echo "cart" ;;
        all|All|ALL) echo "all" ;;
        *) echo "" ;;
    esac
}

toggle_task() {
    local task=$(resolve_task "$1")
    local mode="$2"

    if [ -z "$task" ]; then
        echo -e "${RED}Unknown task: $1${NC}"
        echo "Valid: 1, 2, 3, 4, 5, cart, all"
        exit 1
    fi

    cd "$PROJECT_ROOT"

    local desc="${TASK_DESC[$task]}"
    local files="${TASK_FILES[$task]}"
    local has_patch="${HAS_PATCH[$task]}"

    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"
    echo -e " Task ${task}: ${desc}"
    echo -e "${CYAN}═══════════════════════════════════════════════════════════════${NC}"

    case "$mode" in
        old|OLD)
            if [ "${NEW_FILE_TASK[$task]:-false}" = "true" ]; then
                echo -e "  Switching ${YELLOW}→ OLD${NC} code for: ${files}"
                local bad_file="$PATCH_DIR/current/$(basename $(echo $files | awk '{print $1}') .php)_bad.php"
                if [ -f "$bad_file" ]; then
                    cp "$bad_file" $(echo $files | awk '{print $1}') 2>/dev/null
                    echo -e "  ${GREEN}✓${NC} OLD code restored from backup.\n"
                else
                    echo -e "  ${RED}✗ Bad version not found at $bad_file${NC}"
                    return 1
                fi
                return 0
            fi
            if [ "$has_patch" = false ]; then
                echo -e "  ${YELLOW}⚠ This task has no toggleable code changes.${NC}"
                echo -e "    The 'bad' code is only documented as comments in the file."
                echo -e "    Current source already has the 'good' implementation.\n"
                return 0
            fi
            echo -e "  Switching ${YELLOW}→ OLD${NC} code for: ${files}"
            if [ -z "$files" ]; then
                echo -e "  ${RED}✗ No files defined for task $1${NC}"
                return 1
            fi
            if ! git diff --quiet -- $files 2>/dev/null; then
                echo -e "  ${YELLOW}ⓘ Local changes will be reverted for these files.${NC}"
            fi
            git checkout HEAD -- $files 2>/dev/null || {
                echo -e "  ${RED}✗ Failed to checkout $files from HEAD${NC}"
                return 1
            }
            echo -e "  ${GREEN}✓${NC} OLD code restored.\n"
            ;;

        new|NEW)
            if [ "${NEW_FILE_TASK[$task]:-false}" = "true" ]; then
                echo -e "  Switching ${GREEN}→ NEW${NC} code for: ${files}"
                local good_file="$PATCH_DIR/current/$(basename $(echo $files | awk '{print $1}') .php)_good.php"
                if [ -f "$good_file" ]; then
                    cp "$good_file" $(echo $files | awk '{print $1}') 2>/dev/null
                    echo -e "  ${GREEN}✓${NC} NEW code restored from backup.\n"
                else
                    echo -e "  ${RED}✗ Good version not found at $good_file${NC}"
                    return 1
                fi
                return 0
            fi
            if [ "$has_patch" = false ]; then
                echo -e "  ${GREEN}✓ Already on NEW code (no toggleable changes).${NC}\n"
                return 0
            fi
            echo -e "  Switching ${GREEN}→ NEW${NC} code for: ${files}"

            save_patches

            git checkout HEAD -- $files 2>/dev/null || {
                echo -e "  ${RED}✗ Failed to checkout $files from HEAD${NC}"
                return 1
            }

            local patch_file
            patch_file=$(PATCH_FILE "$task")

            if [ -f "$patch_file" ] && [ -s "$patch_file" ]; then
                if git apply "$patch_file" 2>/dev/null; then
                    echo -e "  ${GREEN}✓${NC} NEW code applied.\n"
                else
                    echo -e "  ${RED}✗ Failed to apply patch.${NC}"
                    echo -e "    The patch may be stale or conflict with other changes."
                    echo -e "    Re-save patches: ./scripts/toggle_code.sh --save"
                    echo -e "    Then retry:      git checkout HEAD -- $files && ./scripts/toggle_code.sh $task new"
                    return 1
                fi
            else
                echo -e "  ${YELLOW}⚠ No saved patch found for task ${task}.${NC}"
                echo -e "    Run with NEW code active first to save patches, or use:"
                echo -e "      ./scripts/toggle_code.sh --save"
                echo ""
            fi
            ;;

        show|status)
            echo -e "  Files: ${files}\n"
            for f in $files; do
                if [ "${NEW_FILE_TASK[$task]:-false}" = "true" ]; then
                    echo -e "    ${YELLOW}⚠${NC} $f — new file (toggle via file copy)"
                elif git diff --quiet -- "$f" 2>/dev/null; then
                    echo -e "    ${GREEN}✓${NC} $f — matches HEAD (OLD code, or NEW already committed)"
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


if [ "${1:-}" = "--save" ]; then
    force_save_patches
    exit 0
fi

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
    echo -e "  6     — Caching (ProductController)"
    echo -e "  7     — Concurrency Control (OptimisticInventoryService)"
    echo -e "  8     — ACID Checkout (CheckoutService)"
    echo -e "  cart  — Cart Reservation (CartService, Product, controllers)"
    echo -e "  all   — All toggleable tasks"
    echo ""
    echo -e "${BOLD}Modes:${NC}"
    echo -e "  old   — Switch to OLD/BAD version"
    echo -e "  new   — Switch to NEW/GOOD version"
    echo -e "  show  — Show current status of tracked files"
    echo ""
    echo -e "${BOLD}Flags:${NC}"
    echo -e "  --save  — Force re-save all patches from current state"
    echo ""
    echo -e "${BOLD}Examples:${NC}"
    echo -e "  ./scripts/toggle_code.sh 1 old    # Switch Task 1 to OLD code"
    echo -e "  ./scripts/toggle_code.sh cart new  # Switch cart to NEW code"
    echo -e "  ./scripts/toggle_code.sh all show  # Show status"
    echo -e "  ./scripts/toggle_code.sh --save    # Re-save patches"
    echo ""
    exit 0
fi

toggle_task "$1" "${2:-show}"
