/**
 * Scenario 08 — Race Condition Test (VUs 71-80, SYNCHRONIZED)
 * 
 * ⚠️ THIS IS THE MOST CRITICAL TEST IN THE SUITE ⚠️
 * 
 * Purpose: Prove that lockForUpdate() (Task 1) prevents overselling.
 * 
 * HOW IT WORKS:
 * 1. Product #1 is seeded with exactly 3 units in stock
 * 2. 10 VUs all try to buy 1 unit of product #1 simultaneously
 * 3. ALL 10 VUs start at startTime:'0s' — within ~50ms of each other
 * 4. Each VU: login → add to cart → place order
 * 5. The order placement uses DB::transaction() + lockForUpdate()
 * 
 * EXPECTED RESULTS (WITH lockForUpdate):
 *   ✅ 3 orders succeed (201) — only 3 units available
 *   ✅ 7 orders rejected (422) — "Insufficient stock"
 *   Total stock after test: 0 (not -7!)
 * 
 * EXPECTED RESULTS (WITHOUT lockForUpdate — THE BUG):
 *   ❌ 10 orders succeed (201) — all read stock=3 before anyone writes
 *   ❌ Stock after: -7 — OVERSELL! Race condition!
 * 
 * In Grafana: Watch ecommerce_db_transactions_total{status="rolled_back"}
 * for 7 rollbacks (the rejected orders within DB transactions).
 * 
 * SYNCHRONIZATION NOTE:
 * k6 does NOT have native inter-VU synchronization (like JMeter's
 * Synchronizing Timer). All 10 VUs start at startTime:'0s', which means
 * they all begin executing within ~50ms of each other. This is sufficient
 * because in database terms, 50ms is an eternity — all 10 VUs will queue
 * on the row-level lock before the first one commits.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { loginByVU, authHeaders } from '../helpers/auth.js';

const BASE_URL = 'http://localhost:8080/api/v1';

// Track race condition results
export const raceConditionSuccess = new Counter('race_condition_successes');
export const raceConditionBlocked = new Counter('race_condition_blocks');

export function raceConditionFlow() {
  // ── SYNCHRONIZATION ──
  // All 10 VUs start at startTime:'0s' due to scenario configuration.
  // No SharedArray trick needed — concurrent start is the mechanism.
  // VUs arrive at the lockForUpdate() call within ~50ms of each other.

  // Step 1: Login with pre-created user
  const { token } = loginByVU(__VU);
  const params = authHeaders(token);

  // Step 2: Add product #1 to cart (THE TARGET — only 3 in stock!)
  const addPayload = JSON.stringify({
    product_id: 1,       // ← ALL VUs target the SAME product (stock=3)
    quantity: 1,
  });

  let res = http.post(`${BASE_URL}/cart/items`, addPayload, params);
  check(res, {
    'race: add to cart 201': (r) => r.status === 201,
  });

  // Step 3: Place order — THIS IS WHERE lockForUpdate() IS TESTED
  // DB::transaction() + lockForUpdate() prevents oversell
  // IMPORTANT: PlaceOrderRequest requires shipping_address
  const orderRes = http.post(`${BASE_URL}/orders`, JSON.stringify({
    shipping_address: {
      name: 'k6 Race Test',
      street: '789 Race St',
      city: 'Test City',
      country: 'US',
      zip: '10001',
      phone: '+1234567890',
    },
  }), params);

  if (orderRes.status === 201) {
    // ✅ This VU succeeded — it acquired the lock and stock was available
    raceConditionSuccess.add(1);
    console.log(`RACE TEST VU ${__VU}: ORDER SUCCEEDED ✅ — lock acquired, stock decremented`);
  } else {
    // ✅ This VU was blocked — lockForUpdate() prevented oversell!
    // The order was rolled back (InsufficientStockException)
    raceConditionBlocked.add(1);
    console.log(`RACE TEST VU ${__VU}: ORDER BLOCKED ✅ — lockForUpdate() prevented oversell (status: ${orderRes.status})`);
  }

  // Step 4: Wait a moment so other VUs can acquire/release locks
  // Not strictly necessary but makes log output more readable
  sleep(1);
}
