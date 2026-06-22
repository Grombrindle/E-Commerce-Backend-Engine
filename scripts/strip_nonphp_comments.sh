#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_ROOT="${SCRIPT_DIR%/scripts}"

# ── Process docker-compose.yml ────────────────────────────
echo "Processing docker-compose.yml..."

# Add Task 5 bad-version comment at the top
cat > /tmp/docker_compose_header.yml << 'YAML'
# ═══════════════════════════════════════════════════════════════════════
# BEFORE — Task 5: Single Unscaled Service (The Problem)
# ═══════════════════════════════════════════════════════════════════════
#
# Bad docker-compose (single instance, no LB):
#
#  services:
#    app:
#      build: .
#      ports:
#        - "8000:8000"       # ⚠ Direct exposure, no Nginx
#      # ⚠ Only ONE container — no redundancy
#      # ⚠ No Redis — queue runs sync (QUEUE_CONNECTION=sync)
#      # ⚠ No load balancer — single point of failure
#
# ═══════════════════════════════════════════════════════════════════════
# AFTER (current code): 3 app instances + Nginx + Redis + Prometheus + Grafana
# ═══════════════════════════════════════════════════════════════════════

YAML

# Remove all comments from docker-compose.yml while preserving structure
# Comments that are inline (after a config value) are also removed
sed -i \
  -e '/^[[:space:]]*#/d' \
  -e 's/[[:space:]]*#.*$//' \
  -e '/^[[:space:]]*$/d' \
  "$PROJECT_ROOT/docker-compose.yml"

# Prepend the bad-version header
{
  cat /tmp/docker_compose_header.yml
  cat "$PROJECT_ROOT/docker-compose.yml"
} > /tmp/docker_compose_new.yml
mv /tmp/docker_compose_new.yml "$PROJECT_ROOT/docker-compose.yml"

# ── Process deploy/nginx/load-balancer.conf ──────────────
echo "Processing deploy/nginx/load-balancer.conf..."

# Add Task 5 bad-version comment at the top
cat > /tmp/nginx_header.conf << 'NGINX'
# ═══════════════════════════════════════════════════════════════════════
# BEFORE — Task 5: Single Server (The Problem)
# ═══════════════════════════════════════════════════════════════════════
#
# Bad config (single server, no load balancing):
#
#  server {
#      listen 80;
#      server_name api.example.com;
#      location / {
#          proxy_pass http://localhost:8000;  # ⚠ Single backend
#      }
#  }
#
# ═══════════════════════════════════════════════════════════════════════
# AFTER (current code): Weighted round-robin (3:2:1) + FastCGI + rate limiting
# ═══════════════════════════════════════════════════════════════════════

NGINX

# Remove all comments from nginx config
sed -i \
  -e '/^[[:space:]]*#/d' \
  -e 's/[[:space:]]*#.*$//' \
  -e '/^[[:space:]]*$/d' \
  "$PROJECT_ROOT/deploy/nginx/load-balancer.conf"

# Prepend the bad-version header
{
  cat /tmp/nginx_header.conf
  cat "$PROJECT_ROOT/deploy/nginx/load-balancer.conf"
} > /tmp/nginx_new.conf
mv /tmp/nginx_new.conf "$PROJECT_ROOT/deploy/nginx/load-balancer.conf"

echo "Done processing non-PHP files."
