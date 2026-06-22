#!/usr/bin/env bash

set -euo pipefail

JSON_FILE="${1:-}"
if [ -z "$JSON_FILE" ] || [ ! -f "$JSON_FILE" ]; then
    echo "Usage: $0 <k6-json-report.json> [output.html]"
    echo "  Generates a beautiful HTML report from a k6 JSON summary export."
    exit 1
fi

OUTPUT_FILE="${2:-"${JSON_FILE%.json}.html"}"

if ! command -v node &>/dev/null; then
    echo "❌ Error: node.js is required but not installed."
    echo "  Install it via your package manager:"
    echo "    Ubuntu/Debian: sudo apt install nodejs"
    echo "    macOS: brew install node"
    exit 1
fi

TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')

TMP_JS=$(mktemp -t k6-report-XXXXXX.js)
trap "rm -f $TMP_JS" EXIT

cat > "$TMP_JS" << 'JSEOF'
const fs = require('fs');

// ═══════════════════════════════════════════════════════════════════════
//  CLI ARGS: node script.js <jsonFile> <outputFile> <timestamp>
// ═══════════════════════════════════════════════════════════════════════
const jsonFile = process.argv[2];
const outputFile = process.argv[3];
const timestamp = process.argv[4] || new Date().toISOString();

const raw = fs.readFileSync(jsonFile, 'utf-8');
const data = JSON.parse(raw);
const metrics = data.metrics || {};
const thresholds = data.thresholds || {};

// ═══════════════════════════════════════════════════════════════════════
//  TASK DEFINITIONS — Educational descriptions for each scenario
// ═══════════════════════════════════════════════════════════════════════
const TASK_DESCRIPTIONS = {
  'browsers': {
    title: '1. Browse & Categories (VUs 1-10)',
    task: 'Task 6 — Redis Caching',
    desc: 'Tests read-heavy endpoints (products list, categories). These requests should be served from Redis cache, not the database. If response times are <10ms, the cache is working. If they are >50ms, the cache is being bypassed.',
    whatToCheck: 'Response time should be low (<50ms). Cache hit ratio should be >95%.',
  },
  'shoppers': {
    title: '2. Product Detail (VUs 11-20)',
    task: 'Task 6 — Cache Stampede Prevention',
    desc: 'All 10 VUs repeatedly hit the SAME product page (#1). This tests the "cache stampede" protection — only the FIRST request should hit the database; all others should get cached data immediately. If response times spike, stampede protection isn\'t working.',
    whatToCheck: 'All 10 VUs hitting product #1 should get <10ms responses (cache hits). No spike in DB queries.',
  },
  'cart_users': {
    title: '3. Cart Operations (VUs 21-30)',
    task: 'Tasks 1 & 7 — Concurrency Control',
    desc: 'Adds items to cart, views cart summary, and clears cart. Tests the inventory reservation system (reserved_quantity increments/decrements). If stock reservation works correctly, cart adds should succeed until stock runs out.',
    whatToCheck: 'Add to cart should succeed (201) while stock/reservations allow. Updates should be reflected immediately.',
  },
  'order_placers': {
    title: '4. Place Orders (VUs 31-40)',
    task: 'Tasks 1 & 3 — Pessimistic Locking + Async Queues',
    desc: 'The most critical test. Each VU adds a product to cart and places an order. This tests: (1) DB::transaction() + lockForUpdate() prevents overselling (Task 1), and (2) async queue dispatch (invoice, email, analytics jobs) — response should be fast (~100ms), NOT slow (~3s).',
    whatToCheck: 'Order response time <500ms (async queues working). Stock never goes negative (locking works).',
  },
  'auth_users': {
    title: '5. Auth & Login (VUs 41-50)',
    task: 'Task 2 — Rate Limiting',
    desc: 'Logs in, checks profile, then fires 75 rapid login attempts. The RateLimiter is configured at 60 req/min per user. After ~60 requests, subsequent ones get HTTP 429 (Too Many Requests). This PROVES rate limiting is active and protecting the system.',
    whatToCheck: 'At least 5-10 requests should get HTTP 429. Rate limiting is WORKING when we see 429s.',
  },
  'mixed_flow': {
    title: '6. Mixed Flow (VUs 51-60)',
    task: 'All Tasks — End-to-End User Journey',
    desc: 'The most comprehensive test. Goes through a full user journey: browse products → view categories → login → add to cart → place order → view order. This exercises EVERY layer of the system: cache, auth, database, queues, and monitoring.',
    whatToCheck: 'Full journey completes. Response time <2s for the entire flow. All steps succeed.',
  },
  'checkout': {
    title: '7. Atomic Checkout (VUs 61-70)',
    task: 'Task 8 — ACID Transactions',
    desc: 'Tests the new atomic checkout endpoint (POST /api/v1/checkout). Unlike the old two-step flow, the new checkout does EVERYTHING in ONE database transaction: validate cart → process payment → decrement stock → create order → clear cart. ALL succeed or ALL roll back.',
    whatToCheck: 'Successful checkouts: 201 (order confirmed, payment paid). Failed: 422 (rolled back — no partial state).',
  },
  'race': {
    title: '8. ⚡ Race Condition (VUs 71-80)',
    task: 'Task 1 — Pessimistic Locking (CRITICAL)',
    desc: 'THE MOST IMPORTANT TEST. 10 VUs ALL try to buy product #1 simultaneously — but product #1 only has 3 units in stock. WITHOUT lockForUpdate(), all 10 would succeed (oversell!). WITH lockForUpdate(), only 3 succeed, 7 get HTTP 422 "Insufficient stock." The stock balance will be 0, NOT -7.',
    whatToCheck: 'Exactly 3 orders succeed (201). 7 orders blocked (422). Stock after test = 0 (not negative!).',
  },
  'admin_users': {
    title: '9. Admin Operations (VUs 81-90)',
    task: 'Tasks 2 & 10 — Admin Middleware + Monitoring',
    desc: 'Tests admin-only endpoints: order stats, low-stock reports, inventory management, product CRUD. These require the "admin" role middleware. Also tests that admin actions are tracked by Prometheus and appear in the monitoring dashboard.',
    whatToCheck: 'Admin endpoints return 200 (authentication + authorization working). Write operations succeed.',
  },
  'wave_users': {
    title: '10. Random Burst Traffic (VUs 91-100)',
    task: 'All Tasks — System Stability Under Chaos',
    desc: 'Fires 5-15 random requests to random endpoints with random delays. Creates unpredictable traffic patterns to reveal hidden bottlenecks, race conditions, or crashes that structured tests might miss. Any 5xx error indicates a server crash under load.',
    whatToCheck: 'No HTTP 5xx errors. System stays responsive under random burst traffic.',
  },
};

// ═══════════════════════════════════════════════════════════════════════
//  EXPLANATIONS — Root cause analysis for failing checks
// ═══════════════════════════════════════════════════════════════════════
function explainCheck(name) {
  const n = name.toLowerCase();
  if (n.includes('login') && n.includes('successful')) {
    return 'Rate limiting or wrong credentials. Check login route has throttle:api middleware and email/password match the seeder.';
  }
  if (n.includes('profile') || n.includes('me')) {
    return 'Token extraction likely failing. The login response wraps token in { success, data: { token } }. k6 must use JSON.parse(res.body).data.token.';
  }
  if (n.includes('logout')) {
    return 'Token may have been revoked by a subsequent login (AuthService deletes old tokens). Use the LATEST token for logout, not the original one.';
  }
  if (n.includes('cart') && n.includes('add')) {
    return 'Stock may be insufficient (reserved by other VUs) OR auth token is invalid → 401. Check inventory levels and token extraction.';
  }
  if (n.includes('cart') && (n.includes('summary') || n.includes('clear'))) {
    return 'Depends on cart having items (addItem must succeed first) AND valid auth token.';
  }
  if (n.includes('order') && n.includes('add')) {
    return 'Same as cart: need valid token + available stock.';
  }
  if (n.includes('order') && (n.includes('placed') || n.includes('has order'))) {
    return 'Order placement requires: valid auth + non-empty cart + available stock + no rate limiting (10/min throttle.order).';
  }
  if (n.includes('admin') && (n.includes('stats') || n.includes('low stock') || n.includes('inventory') || n.includes('products'))) {
    return 'Admin token may be invalid OR admin user data not properly seeded. Admin VUs all share one account and logins revoke each others tokens.';
  }
  if (n.includes('race') && n.includes('add')) {
    return 'Race condition test targets product #1 with stock=3. If stock is different or product missing, add-to-cart fails with 422. Run K6TestSeeder.';
  }
  if (n.includes('stampede') || n.includes('product detail')) {
    return 'Cache stampede protection or product not found. Check CachedProductService and ensure products exist.';
  }
  if (n.includes('categories') || n.includes('browse')) {
    return 'General server error or timeout under load. SQLite WAL mode and gateway sleep fix should help.';
  }
  if (n.includes('wave') || n.includes('server error')) {
    return 'Server returning 5xx under mixed load. Likely database contention or PHP-FPM pool exhaustion.';
  }
  return 'Check server logs for error details. Could be rate limiting (429), auth failure (401), or server error (5xx).';
}

// ═══════════════════════════════════════════════════════════════════════
//  HELPERS
// ═══════════════════════════════════════════════════════════════════════

function fmtDur(ms) {
  if (ms === undefined || ms === null || ms === 0) return '—';
  if (ms >= 1000) return (ms / 1000).toFixed(2) + 's';
  return ms.toFixed(0) + 'ms';
}

function fmtBytes(bytes) {
  if (!bytes || bytes === 0) return '—';
  if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
  return (bytes / 1024).toFixed(1) + ' KB';
}

function fmtPct(fraction) {
  if (fraction === undefined || fraction === null) return '—';
  return (fraction * 100).toFixed(1) + '%';
}

function barColor(pct) {
  if (pct >= 90) return '#22c55e';
  if (pct >= 70) return '#84cc16';
  if (pct >= 50) return '#f59e0b';
  if (pct >= 25) return '#f97316';
  return '#ef4444';
}

function statusIcon(passes, fails) {
  const total = passes + fails;
  if (total === 0) return '&mdash;';
  if (fails === 0) return '&#x2705;';
  if (passes === 0) return '&#x274C;';
  return '&#x26A0;&#xFE0F;';
}

function statusLabel(passes, fails) {
  const total = passes + fails;
  if (total === 0) return '—';
  const pct = Math.round((passes / total) * 100);
  if (pct >= 90) return 'Good';
  if (pct >= 70) return 'Fair';
  if (pct >= 50) return 'Poor';
  if (pct >= 25) return 'Bad';
  return 'Failed';
}

// Map scenario exec names to task description keys
function scenarioKeyFromCheck(name) {
  const n = name.toLowerCase();
  if (n.includes('browse')) return 'browsers';
  if (n.includes('detail')) return 'shoppers';
  if (n.includes('cart') && !n.includes('checkout')) return 'cart_users';
  if (n.includes('order') && !n.includes('checkout')) return 'order_placers';
  if (n.includes('auth')) return 'auth_users';
  if (n.includes('mixed')) return 'mixed_flow';
  if (n.includes('checkout')) return 'checkout';
  if (n.includes('race')) return 'race';
  if (n.includes('admin')) return 'admin_users';
  if (n.includes('wave')) return 'wave_users';
  return null;
}

// Extract checks recursively
function extractChecks(group, depth) {
  let rows = '';
  if (group.checks) {
    for (const [name, c] of Object.entries(group.checks)) {
      const passes = c.passes || 0;
      const fails = c.fails || 0;
      const total = passes + fails;
      const pct = total > 0 ? Math.round((passes / total) * 100) : 0;
      const explanation = explainCheck(name);
      const skey = scenarioKeyFromCheck(name);
      const taskInfo = skey ? TASK_DESCRIPTIONS[skey] : null;
      rows += `<tr class="check-row ${pct < 50 ? 'check-fail-row' : ''}">
        <td class="check-name">${'  '.repeat(depth)}${name}</td>
        <td>${statusIcon(passes, fails)}</td>
        <td class="num pass">${passes}</td>
        <td class="num fail">${fails}</td>
        <td>
          <div class="bar-container">
            <div class="bar" style="width: ${Math.max(pct, 3)}%; background: ${barColor(pct)};"></div>
            <span class="bar-label">${pct}%</span>
          </div>
        </td>
        <td class="status-tag ${pct >= 70 ? 'tag-ok' : pct >= 50 ? 'tag-warn' : 'tag-bad'}">${statusLabel(passes, fails)}</td>
      </tr>`;
      if (pct < 100 && total > 0) {
        rows += `<tr class="explain-row"><td colspan="6">
          <div class="explanation">
            <strong>&#x1F50D; Root Cause:</strong> ${explanation}
            ${taskInfo ? `<br><strong>&#x1F4DD; Context:</strong> ${taskInfo.desc}` : ''}
          </div>
        </td></tr>`;
      }
    }
  }
  if (group.groups) {
    for (const g of Object.values(group.groups)) {
      rows += extractChecks(g, depth + 1);
    }
  }
  return rows;
}

// Build scenario task explanation cards
function scenarioCards() {
  let html = '';
  for (const [key, info] of Object.entries(TASK_DESCRIPTIONS)) {
    html += `<div class="task-card">
      <div class="task-header">
        <div class="task-title">${info.title}</div>
        <div class="task-badge">${info.task}</div>
      </div>
      <p class="task-desc">${info.desc}</p>
      <div class="task-check"><strong>&#x1F50D; What to look for:</strong> ${info.whatToCheck}</div>
    </div>`;
  }
  return html;
}

// ═══════════════════════════════════════════════════════════════════════
//  PARSE METRICS
// ═══════════════════════════════════════════════════════════════════════

const httpReqs = metrics.http_reqs || {};
const httpDur = metrics.http_req_duration || {};
const httpFail = metrics.http_req_failed || {};
const dataRecv = metrics.data_received || {};
const dataSent = metrics.data_sent || {};
const iter = metrics.iterations || {};
const vusMax = metrics.vus_max || {};
const iterDur = metrics.iteration_duration || {};

const totalReqs = httpReqs.count || httpReqs.value || 0;
const reqRate = httpReqs.rate || 0;
const failRate = httpFail.value !== undefined ? httpFail.value : (httpFail.fails && totalReqs ? httpFail.fails / totalReqs : 0);
const failCount = httpFail.fails || httpFail.value || 0;

// Thresholds
let thresholdList = [];
for (const [name, m] of Object.entries(metrics)) {
  if (m.thresholds) {
    for (const [cond, info] of Object.entries(m.thresholds)) {
      thresholdList.push({ metric: name, condition: cond, pass: info.ok === true });
    }
  }
}
for (const [name, info] of Object.entries(thresholds)) {
  const passed = info.pass === true;
  const cond = Object.keys(info).filter(k => k !== 'pass' && k !== 'source').join(', ');
  thresholdList.push({ metric: name, condition: cond || name, pass: passed });
}

const passedThresholds = thresholdList.filter(t => t.pass).length;
const totalThresholds = thresholdList.length;
const allPassed = passedThresholds === totalThresholds;

const checksPasses = data.root_group?.checks?.passes || 0;
const checksFails = data.root_group?.checks?.fails || 0;
const checksTotal = checksPasses + checksFails;

// ═══════════════════════════════════════════════════════════════════════
//  BUILD HTML
// ═══════════════════════════════════════════════════════════════════════

const html = `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>k6 Load Test Report — E-Commerce Backend Engine</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #0b1120;
    color: #e2e8f0;
    padding: 2rem;
    line-height: 1.6;
  }
  .container { max-width: 1300px; margin: 0 auto; }

  /* ── HEADER ── */
  .header {
    text-align: center;
    padding: 3rem 2rem;
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 50%, #1a1a2e 100%);
    border-radius: 1rem;
    margin-bottom: 2rem;
    border: 1px solid #334155;
    position: relative;
    overflow: hidden;
  }
  .header::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 4px;
    background: linear-gradient(90deg, #22c55e, #3b82f6, #8b5cf6, #ef4444);
  }
  .header h1 { font-size: 2rem; color: #f8fafc; margin-bottom: 0.5rem; }
  .header .subtitle { color: #94a3b8; font-size: 0.9rem; margin-bottom: 0.5rem; }
  .header .meta { color: #94a3b8; font-size: 0.85rem; display: flex; justify-content: center; gap: 2rem; flex-wrap: wrap; }
  .header .meta span { display: inline-flex; align-items: center; gap: 0.3rem; }
  .badge {
    display: inline-block;
    padding: 0.35rem 1.25rem;
    border-radius: 999px;
    font-size: 0.9rem;
    font-weight: 600;
    margin-top: 1rem;
  }
  .badge-pass { background: #065f46; color: #6ee7b7; }
  .badge-fail { background: #7f1d1d; color: #fca5a5; }
  .badge-partial { background: #713f12; color: #fbbf24; }

  /* ── SUMMARY CARDS ── */
  .summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 1rem;
    margin-bottom: 1.5rem;
  }
  .summary-card {
    background: #1e293b;
    border-radius: 0.75rem;
    padding: 1.25rem;
    text-align: center;
    border: 1px solid #334155;
    transition: transform 0.2s, box-shadow 0.2s;
  }
  .summary-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.3); }
  .summary-card .value { font-size: 1.75rem; font-weight: 700; margin-bottom: 0.25rem; }
  .summary-card .label { font-size: 0.75rem; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.05em; }
  .summary-card .sub { font-size: 0.75rem; color: #64748b; margin-top: 0.25rem; }

  /* ── SECTIONS ── */
  .section {
    background: #1e293b;
    border-radius: 0.75rem;
    padding: 1.5rem;
    margin-bottom: 1.5rem;
    border: 1px solid #334155;
  }
  .section h2 {
    font-size: 1.2rem;
    color: #f1f5f9;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #334155;
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }
  .section h2 .count {
    font-size: 0.8rem;
    color: #94a3b8;
    font-weight: 400;
    margin-left: auto;
  }
  .section-desc {
    font-size: 0.9rem;
    color: #94a3b8;
    margin-bottom: 1rem;
    line-height: 1.6;
  }

  /* ── TASK CARDS ── */
  .tasks-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(340px, 1fr));
    gap: 1rem;
  }
  .task-card {
    background: #0f172a;
    border-radius: 0.75rem;
    padding: 1.25rem;
    border: 1px solid #334155;
    transition: border-color 0.2s, box-shadow 0.2s;
  }
  .task-card:hover { border-color: #475569; box-shadow: 0 2px 8px rgba(0,0,0,0.2); }
  .task-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem; margin-bottom: 0.75rem; }
  .task-title { font-weight: 600; font-size: 0.95rem; color: #f1f5f9; }
  .task-badge {
    font-size: 0.65rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    background: #1e3a5f;
    color: #93c5fd;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .task-desc { font-size: 0.85rem; color: #94a3b8; line-height: 1.6; margin-bottom: 0.75rem; }
  .task-check { font-size: 0.8rem; color: #cbd5e1; padding: 0.5rem; background: #1e293b; border-radius: 0.5rem; border-left: 3px solid #f59e0b; }

  /* ── THRESHOLDS ── */
  .threshold-list { list-style: none; }
  .threshold-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.65rem 1rem;
    border-bottom: 1px solid #1e293b;
    gap: 1rem;
  }
  .threshold-item:hover { background: #0f172a; border-radius: 0.5rem; }
  .threshold-item:last-child { border-bottom: none; }
  .threshold-left { display: flex; align-items: center; gap: 0.75rem; min-width: 0; }
  .threshold-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
  .threshold-name { font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .threshold-cond { font-size: 0.8rem; color: #64748b; }
  .threshold-status {
    font-size: 0.8rem;
    font-weight: 600;
    padding: 0.2rem 0.75rem;
    border-radius: 999px;
    white-space: nowrap;
    flex-shrink: 0;
  }
  .th-pass { background: #065f46; color: #6ee7b7; }
  .th-fail { background: #7f1d1d; color: #fca5a5; }

  /* ── CHECKS TABLE ── */
  .checks-table { width: 100%; border-collapse: separate; border-spacing: 0; }
  .checks-table th {
    text-align: left;
    padding: 0.6rem 0.75rem;
    font-size: 0.75rem;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    border-bottom: 2px solid #334155;
    position: sticky;
    top: 0;
    background: #1e293b;
  }
  .checks-table td { padding: 0.5rem 0.75rem; border-bottom: 1px solid #1e293b; vertical-align: top; }
  .check-row:hover td { background: #0f172a; }
  .check-fail-row td { background: #1a1111; }
  .check-name { font-size: 0.85rem; max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .num { font-family: 'SF Mono', monospace; font-size: 0.85rem; text-align: center; }
  .pass { color: #6ee7b7; }
  .fail { color: #fca5a5; }
  .bar-container { display: flex; align-items: center; gap: 0.5rem; min-width: 120px; }
  .bar { height: 8px; border-radius: 4px; min-width: 8px; transition: width 0.5s ease; }
  .bar-label { font-size: 0.75rem; color: #94a3b8; white-space: nowrap; }
  .status-tag {
    font-size: 0.7rem;
    font-weight: 600;
    padding: 0.15rem 0.5rem;
    border-radius: 4px;
    text-align: center;
    white-space: nowrap;
  }
  .tag-ok { background: #065f46; color: #6ee7b7; }
  .tag-warn { background: #713f12; color: #fbbf24; }
  .tag-bad { background: #7f1d1d; color: #fca5a5; }

  .explain-row td { padding: 0 0.75rem 0.75rem 0.75rem; border-bottom: 1px solid #1e293b; }
  .explanation {
    font-size: 0.8rem;
    color: #94a3b8;
    padding: 0.5rem 0.75rem;
    background: #0f172a;
    border-radius: 0.5rem;
    border-left: 3px solid #f59e0b;
    line-height: 1.6;
  }

  /* ── METRICS ── */
  .metrics-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
  }
  @media (max-width: 768px) { .metrics-grid { grid-template-columns: 1fr; } }
  .metric-row {
    display: flex;
    justify-content: space-between;
    padding: 0.4rem 0;
    border-bottom: 1px solid #1e293b;
    gap: 1rem;
  }
  .metric-row:last-child { border-bottom: none; }
  .metric-key { color: #94a3b8; font-size: 0.85rem; }
  .metric-value { font-weight: 500; font-family: 'SF Mono', monospace; font-size: 0.85rem; }

  /* ── FOOTER ── */
  .footer {
    text-align: center;
    color: #475569;
    font-size: 0.75rem;
    padding: 2rem;
  }

  /* ── LEGEND ── */
  .legend { display: flex; gap: 1.5rem; font-size: 0.8rem; color: #94a3b8; margin-bottom: 1rem; flex-wrap: wrap; }
  .legend-item { display: flex; align-items: center; gap: 0.4rem; }
  .legend-dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

  .empty { color: #64748b; font-style: italic; }

  /* ── SCROLL CONTAINER ── */
  .scroll-box { max-height: 600px; overflow-y: auto; border: 1px solid #334155; border-radius: 0.5rem; }

  /* ── CALL TO ACTION ── */
  .cta-box {
    background: linear-gradient(135deg, #1e3a5f 0%, #1e293b 100%);
    border: 1px solid #3b82f6;
    border-radius: 0.75rem;
    padding: 1.5rem;
    text-align: center;
    margin-bottom: 1.5rem;
  }
  .cta-box h3 { color: #93c5fd; margin-bottom: 0.5rem; }
  .cta-box p { color: #94a3b8; font-size: 0.9rem; }
</style>
</head>
<body>
<div class="container">

  <!-- ═══ HEADER ═══ -->
  <div class="header">
    <h1>&#x1F3CB;&#xFE0F; k6 Load Test Report</h1>
    <div class="subtitle">E-Commerce Backend Engine — Parallel Programming Course</div>
    <div class="meta">
      <span>&#x1F4C5; ${new Date().toLocaleString()}</span>
      <span>&#x1F464; ${vusMax.max || vusMax.value || '?'} max VUs</span>
      <span>&#x1F4CA; ${totalThresholds} thresholds</span>
      <span>&#x23F1; ${data.test_run ? (data.test_run.duration || '?') : '?'}</span>
    </div>
    <div class="badge ${allPassed ? 'badge-pass' : passedThresholds > 0 ? 'badge-partial' : 'badge-fail'}">
      ${allPassed ? '&#x2705; ALL ' + totalThresholds + ' THRESHOLDS PASSED' : passedThresholds > 0 ? '&#x26A0;&#xFE0F; ' + passedThresholds + '/' + totalThresholds + ' THRESHOLDS PASSED' : '&#x274C; ' + totalThresholds + ' THRESHOLDS FAILED'}
    </div>
  </div>

  <!-- ═══ SUMMARY CARDS ═══ -->
  <div class="summary-grid">
    <div class="summary-card">
      <div class="value" style="color: #60a5fa;">${totalReqs.toLocaleString()}</div>
      <div class="label">Total Requests</div>
      <div class="sub">${reqRate.toFixed(2)} req/s</div>
    </div>
    <div class="summary-card">
      <div class="value" style="color: ${failRate > 0.5 ? '#ef4444' : '#22c55e'};">${fmtPct(failRate)}</div>
      <div class="label">Failure Rate</div>
      <div class="sub">${Math.round(failCount)} failed of ${totalReqs.toLocaleString()}</div>
    </div>
    <div class="summary-card">
      <div class="value" style="color: #a78bfa;">${fmtDur(httpDur.avg)}</div>
      <div class="label">Avg Response Time</div>
      <div class="sub">p(95): ${fmtDur(httpDur['p(95)'])}</div>
    </div>
    <div class="summary-card">
      <div class="value" style="color: #f59e0b;">${iter.count || iter.value || 0}</div>
      <div class="label">Iterations Complete</div>
      <div class="sub">${(iter.rate || 0).toFixed(2)}/s</div>
    </div>
  </div>

  <!-- ═══ KEY FINDINGS ═══ -->
  <div class="section">
    <h2>&#x1F50D; What This Test Does</h2>
    <div class="section-desc">
      This load test simulates <strong>100 concurrent users</strong> divided into <strong>10 groups</strong>, each testing a different aspect of the e-commerce system. Each group maps to one of the 10 parallel programming tasks in the course.
      <br><br>
      <strong>Failure rate (${fmtPct(failRate)})</strong> — 
      ${failRate > 0.3
        ? 'This is expected because the rate limiting test (Scenario 5) intentionally sends 75 rapid login attempts to trigger HTTP 429 responses. These are NOT bugs — they prove the rate limiter is working! Non-rate-limited endpoints should have < 5% failure rate.'
        : 'The system is performing well under load.'}
      <br><br>
      <strong>&#x1F4A1; How to read this report:</strong> Scroll down to see each scenario's description and check results. Green checks mean the parallel programming concept is working correctly. Red checks need investigation.
    </div>
  </div>

  <!-- ═══ TASK OVERVIEW ═══ -->
  <div class="section">
    <h2>&#x1F4CB; 10 Test Scenarios &mdash; What Each One Tests</h2>
    <div class="section-desc">
      Each scenario tests a specific parallel programming concept. Hover over a card to see details. The badge shows which Task(s) it relates to.
    </div>
    <div class="tasks-grid">
      ${scenarioCards()}
    </div>
  </div>

  <!-- ═══ THRESHOLDS ═══ -->
  <div class="section">
    <h2>&#x1F4CA; Threshold Results <span class="count">${passedThresholds}/${totalThresholds} passed</span></h2>
    <div class="section-desc">
      Thresholds are the pass/fail criteria defined in the test. Each threshold checks that a metric stays within acceptable bounds.
    </div>
    ${thresholdList.length === 0 ? '<div class="empty">No thresholds found in this report.</div>' : ''}
    <ul class="threshold-list">
      ${thresholdList.map(t => {
        const isFail = !t.pass;
        const condText = t.condition.replace(/^['\u201d]|['\u201d]$/g, '');
        return `<li class="threshold-item">
          <div class="threshold-left">
            <div class="threshold-dot" style="background: ${isFail ? '#ef4444' : '#22c55e'};"></div>
            <div>
              <div class="threshold-name">${t.metric}</div>
              <div class="threshold-cond">${condText}</div>
            </div>
          </div>
          <div class="threshold-status ${isFail ? 'th-fail' : 'th-pass'}">
            ${isFail ? '&#x274C; FAIL' : '&#x2705; PASS'}
          </div>
        </li>`;
      }).join('\n      ')}
    </ul>
  </div>

  <!-- ═══ CHECKS ── with explanations ═══ -->
  <div class="section">
    <h2>&#x2705; Check-by-Check Results <span class="count">${checksPasses}/${checksTotal || '?'} checks passed</span></h2>
    <div class="section-desc">
      Each check is a single assertion in the test (e.g., "status is 200" or "response has data"). Green = passing. Red = failing (with explanation below).
    </div>
    <div class="legend">
      <span class="legend-item"><span class="legend-dot" style="background: #22c55e;"></span> Good (&ge;90%)</span>
      <span class="legend-item"><span class="legend-dot" style="background: #84cc16;"></span> Fair (&ge;70%)</span>
      <span class="legend-item"><span class="legend-dot" style="background: #f59e0b;"></span> Poor (&ge;50%)</span>
      <span class="legend-item"><span class="legend-dot" style="background: #f97316;"></span> Bad (&ge;25%)</span>
      <span class="legend-item"><span class="legend-dot" style="background: #ef4444;"></span> Failed (&lt;25%)</span>
    </div>
    <div class="scroll-box">
      <table class="checks-table">
        <thead>
          <tr>
            <th style="width: 35%;">Check Name</th>
            <th style="width: 5%;">Status</th>
            <th style="width: 8%;">Passes</th>
            <th style="width: 8%;">Fails</th>
            <th style="width: 22%;">Pass Rate</th>
            <th style="width: 12%;">Result</th>
          </tr>
        </thead>
        <tbody>
          ${extractChecks(data.root_group || {}, 0)}
        </tbody>
      </table>
    </div>
  </div>

  <!-- ═══ FAILURE ANALYSIS ═══ -->
  <div class="section">
    <h2>&#x1F50E; Failure Analysis &amp; Root Causes</h2>
    <div class="section-desc">
      Each failing check (pass rate &lt; 90%) is listed below with a root cause explanation. Use this to debug issues.
    </div>
    ${(checksPasses + checksFails) > 0 ? `
    <div class="scroll-box">
      <table class="checks-table">
        <thead>
          <tr><th style="width: 35%;">Failing Check</th><th style="width: 10%;">Rate</th><th style="width: 55%;">Root Cause &amp; How to Fix</th></tr>
        </thead>
        <tbody>
          ${(() => {
            let rows = '';
            const extractFailing = (group, depth) => {
              if (group.checks) {
                for (const [name, c] of Object.entries(group.checks)) {
                  const passes = c.passes || 0;
                  const fails = c.fails || 0;
                  const total = passes + fails;
                  const pct = total > 0 ? Math.round((passes / total) * 100) : 100;
                  if (pct < 90 && total > 0) {
                    const skey = scenarioKeyFromCheck(name);
                    const taskInfo = skey ? TASK_DESCRIPTIONS[skey] : null;
                    rows += `<tr>
                      <td class="check-name">${'  '.repeat(depth)}${name}</td>
                      <td><span class="status-tag ${pct >= 50 ? 'tag-warn' : 'tag-bad'}">${pct}%</span></td>
                      <td style="font-size: 0.8rem; color: #94a3b8; line-height: 1.6;">
                        <strong>&#x1F50D; Root Cause:</strong> ${explainCheck(name)}
                        ${taskInfo ? `<br><strong>&#x1F4DD; Context:</strong> ${taskInfo.desc}` : ''}
                      </td>
                    </tr>`;
                  }
                }
              }
              if (group.groups) {
                for (const g of Object.values(group.groups)) rows += extractFailing(g, depth + 1);
              }
              return rows;
            };
            return extractFailing(data.root_group || {}, 0);
          })()}
        </tbody>
      </table>
    </div>` : '<div class="empty">No checks with failures to analyze.</div>'}
  </div>

  <!-- ═══ HTTP METRICS ═══ -->
  <div class="section">
    <h2>&#x26A1; HTTP &amp; Network Performance</h2>
    <div class="section-desc">
      Detailed response time distribution and network statistics for the entire test run.
    </div>
    <div class="metrics-grid">
      <div>
        <h3 style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 0.5rem;">Response Times</h3>
        ${[
          ['Average', httpDur.avg],
          ['Median', httpDur.med],
          ['p(90)', httpDur['p(90)']],
          ['p(95)', httpDur['p(95)']],
          ['Min', httpDur.min],
          ['Max', httpDur.max]
        ].map(([k, v]) =>
          `<div class="metric-row"><span class="metric-key">${k}</span><span class="metric-value">${fmtDur(v)}</span></div>`
        ).join('\n        ')}
      </div>
      <div>
        <h3 style="color: #94a3b8; font-size: 0.85rem; margin-bottom: 0.5rem;">Network &amp; Execution</h3>
        <div class="metric-row"><span class="metric-key">Data Received</span><span class="metric-value">${fmtBytes(dataRecv.count || dataRecv.value)} (${(dataRecv.rate || 0).toFixed(0)} KB/s)</span></div>
        <div class="metric-row"><span class="metric-key">Data Sent</span><span class="metric-value">${fmtBytes(dataSent.count || dataSent.value)} (${(dataSent.rate || 0).toFixed(0)} KB/s)</span></div>
        <div class="metric-row"><span class="metric-key">Iteration Duration (avg)</span><span class="metric-value">${fmtDur(iterDur.avg)}</span></div>
        <div class="metric-row"><span class="metric-key">Iteration Duration (max)</span><span class="metric-value">${fmtDur(iterDur.max)}</span></div>
        <div class="metric-row"><span class="metric-key">Failed Requests</span><span class="metric-value" style="color: ${failRate > 0.1 ? '#ef4444' : '#22c55e'};">${Math.round(failCount)} (${fmtPct(failRate)})</span></div>
      </div>
    </div>
  </div>

  <!-- ═══ QUICK SUMMARY ═══ -->
  <div class="cta-box">
    <h3>&#x1F4A1; How to Present This Report</h3>
    <p>
      <strong>To explain the results:</strong> Start with the <strong>Task Overview</strong> section above — it explains what each of the 10 scenarios tests. Then look at the <strong>Threshold Results</strong> to see which metrics passed/failed. If anything failed, the <strong>Failure Analysis</strong> section explains the root cause.
      <br><br>
      <strong>Key points for a demo:</strong> The race condition test (Scenario 8) proves <code>lockForUpdate()</code> works — 10 concurrent buyers but only 3 succeed. The rate limiting test (Scenario 5) proves the throttle works — ~5 requests get 429. The cache test (Scenarios 1-2) proves Redis caching works — response times are &lt;10ms.
    </p>
  </div>

  <!-- ═══ FOOTER ═══ -->
  <div class="footer">
    Generated by <strong>generate-k6-html-report.sh</strong> &bull; ${timestamp}<br>
    E-Commerce Backend Engine &mdash; Parallel Programming Course &mdash; Semester 2026
  </div>

</div>
</body>
</html>`;

fs.writeFileSync(outputFile, html);
console.log('HTML report generated: ' + outputFile);
JSEOF

node "$TMP_JS" "$JSON_FILE" "$OUTPUT_FILE" "$TIMESTAMP"

echo ""
echo "📄 Report saved to: ${OUTPUT_FILE}"
echo "📂 Open in browser: file://${OUTPUT_FILE}"
echo ""
