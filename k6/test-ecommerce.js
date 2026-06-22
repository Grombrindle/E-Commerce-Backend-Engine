/**
 * ═══════════════════════════════════════════════════════════════════════
 * ╔══════════════════════════════════════════════════════════════════════╗
 * ║  k6 Load Test — E-Commerce Backend Engine                          ║
 * ║  Course: Parallel Programming | Semester 2026                      ║
 * ║  Framework: Laravel 11 | Testing Tool: k6 (Grafana Labs)           ║
 * ╚══════════════════════════════════════════════════════════════════════╝
 * ═══════════════════════════════════════════════════════════════════════
 *
 * ARCHITECTURE:
 *   100 virtual users divided into 10 functional groups (10 VUs each).
 *   Each group runs a different behavioral pattern (browsing, ordering,
 *   admin, etc.). See /scenarios/ for individual implementations.
 *
 * SYNCHRONIZATION:
 *   20 users (Scenarios 7 + 8) start at startTime:'0s' for tight timing
 *   on the race condition and circuit breaker tests. The remaining 80
 *   users start with staggered ramp-ups over 15s.
 *
 * OUTPUT:
 *   k6 run --out output-prometheus-remote=http://localhost:9090/api/v1/write test-ecommerce.js
 *
 * INTEGRATION:
 *   Metrics are exported to Prometheus and visualized in Grafana
 *   (E-Commerce Backend Engine dashboard).
 *
 * ═══════════════════════════════════════════════════════════════════════
 */

// ── Import Scenario Functions (namespaced) ────────────
// Each scenario is a separate file with its own exported function.
// We import them here and re-export so k6's `exec` can find them.

import * as scenario01 from './scenarios/01-browse.js';
import * as scenario02 from './scenarios/02-product-detail.js';
import * as scenario03 from './scenarios/03-cart.js';
import * as scenario04 from './scenarios/04-order.js';
import * as scenario05 from './scenarios/05-auth.js';
import * as scenario06 from './scenarios/06-mixed.js';
import * as scenario07 from './scenarios/07-checkout.js';
import * as scenario08 from './scenarios/08-race-condition.js';
import * as scenario09 from './scenarios/09-admin.js';
import * as scenario10 from './scenarios/10-wave.js';

// ── Test Options ───────────────────────────────────────
export const options = {
  discardResponseBodies: false,

  // ── Thresholds: Pass/Fail Criteria ─────────────────
  thresholds: {
    // Overall: P95 response time < 2s, error rate < 10%
    http_req_duration: ['p(95)<2000'],
    http_req_failed: ['rate<0.10'],

    // Task 1 — Race Condition:
    'race_condition_successes': ['count>2'],   // At least 3 succeeded
    'race_condition_blocks': ['count>6'],       // At least 7 blocked

    // Task 2 — Rate Limiting:
    'rate_limit_hits': ['count>0'],             // At least some 429s

    // Task 3 — Async Queues:
    'order_placement_duration': ['p(95)<500'],  // < 500ms (async = fast)

    // Task 6 — Redis Cache:
    'product_detail_duration': ['p(95)<100'],   // < 100ms (cache hit)

    // Task 8 — ACID Checkout:
    'checkout_duration': ['p(95)<3000'],        // Atomic checkout completes

    // Task 10 — Monitoring:
    'mixed_flow_duration': ['p(95)<2000'],      // End-to-end
  },

  // ── Scenario Definitions ────────────────────────────
  scenarios: {
    // ╔══════════════════════════════════════════════════╗
    // ║  SYNCHRONIZED (20 VUs @ 0s)                       ║
    // ║  Race condition + Circuit breaker tests           ║
    // ╚══════════════════════════════════════════════════╝

    checkout: {
      executor: 'per-vu-iterations',
      vus: 10,
      iterations: 5,
      maxDuration: '2m',
      startTime: '0s',
      exec: 'checkoutFlow',
    },

    race: {
      executor: 'per-vu-iterations',
      vus: 10,
      iterations: 1,
      maxDuration: '1m',
      startTime: '0s',
      exec: 'raceConditionFlow',
    },

    // ╔══════════════════════════════════════════════════╗
    // ║  NORMAL (80 VUs, staggered ramp-up)               ║
    // ╚══════════════════════════════════════════════════╝

    browsers: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '5s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '2s',
      gracefulRampDown: '10s',
      exec: 'browseFlow',
    },

    shoppers: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '5s',
      gracefulRampDown: '10s',
      exec: 'productDetailFlow',
    },

    cart_users: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '8s',
      gracefulRampDown: '10s',
      exec: 'cartFlow',
    },

    order_placers: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '15s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '10s',
      gracefulRampDown: '10s',
      exec: 'orderFlow',
    },

    auth_users: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '10s', target: 10 },
        { duration: '45s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '5s',
      gracefulRampDown: '10s',
      exec: 'authFlow',
    },

    mixed_flow: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '20s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '15s',
      gracefulRampDown: '10s',
      exec: 'mixedFlow',
    },

    admin_users: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '15s', target: 10 },
        { duration: '45s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '12s',
      gracefulRampDown: '10s',
      exec: 'adminFlow',
    },

    wave_users: {
      executor: 'ramping-vus',
      startVUs: 0,
      stages: [
        { duration: '15s', target: 10 },
        { duration: '60s', target: 10 },
        { duration: '10s', target: 0 },
      ],
      startTime: '3s',
      gracefulRampDown: '10s',
      exec: 'waveFlow',
    },
  },
};

// ── Exported Functions (for `exec` in scenarios) ─────
// k6's scenarios system maps the `exec` property to exported
// function names. Each function here delegates to the imported
// scenario module.

export function browseFlow()        { return scenario01.browseFlow(); }
export function productDetailFlow() { return scenario02.productDetailFlow(); }
export function cartFlow()          { return scenario03.cartFlow(); }
export function orderFlow()         { return scenario04.orderFlow(); }
export function authFlow()          { return scenario05.authFlow(); }
export function mixedFlow()         { return scenario06.mixedFlow(); }
export function checkoutFlow()      { return scenario07.checkoutFlow(); }
export function raceConditionFlow() { return scenario08.raceConditionFlow(); }
export function adminFlow()         { return scenario09.adminFlow(); }
export function waveFlow()          { return scenario10.waveFlow(); }
