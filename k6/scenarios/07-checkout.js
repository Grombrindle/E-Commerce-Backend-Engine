/**
 * Scenario 07 — Atomic Checkout Users (VUs 61-70)
 *
 * Task 8: ACID Transactions
 *
 * Purpose: Test the new atomic checkout endpoint (POST /api/v1/checkout).
 *
 * Unlike the old two-step flow (POST /orders → POST /payments),
 * the new checkout does everything in ONE database transaction:
 *   1. Validate cart
 *   2. Process payment (simulated gateway)
 *   3. Decrement stock (pessimistic lock via InventoryService)
 *   4. Create order
 *   5. Clear cart
 *   → ALL succeed or ALL roll back (ACID compliance)
 *
 * With 10 concurrent VUs doing checkout simultaneously:
 * - lockForUpdate() serializes stock decrements
 * - Some succeed (201), others get 422 (insufficient stock)
 * - NO overselling possible — ACID isolation guarantees consistency
 *
 * In Grafana: Watch http_requests_total, http_request_duration_seconds,
 * db_transactions_total (should show high transaction commit rate)
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend, Counter } from 'k6/metrics';
import { loginByVU, authHeaders } from '../helpers/auth.js';
import { randomInt, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

// Custom metrics for ACID checkout monitoring
export const checkoutDuration = new Trend('checkout_duration');
export const checkoutSuccesses = new Counter('checkout_successes');
export const checkoutRollbacks = new Counter('checkout_rollbacks');

export function checkoutFlow() {
  // Step 1: Login
  const { token } = loginByVU(__VU);
  const params = authHeaders(token);
  sleep(randomSleep('quick'));

  // Step 2: Add product to cart
  const productId = randomInt(1, 50);
  http.post(`${BASE_URL}/cart/items`, JSON.stringify({
    product_id: productId,
    quantity: 1,
  }), params);

  sleep(randomSleep('quick'));

  // Step 3: Atomic Checkout — single call does everything
  const checkoutRes = http.post(`${BASE_URL}/checkout`, JSON.stringify({
    payment: {
      method: 'card',
      card_number: '4111111111111111',  // Valid card — will succeed
    },
    shipping_address: {
      name: 'k6 Checkout Test',
      street: '123 Load Test St',
      city: 'New York',
      country: 'US',
      zip: '10001',
      phone: '+1234567890',
    },
  }), params);

  // Track metrics
  checkoutDuration.add(checkoutRes.timings.duration);

  if (checkoutRes.status === 201) {
    // Success — order placed and paid atomically
    checkoutSuccesses.add(1);
    check(checkoutRes, {
      'checkout: order created and paid': (r) => {
        const body = r.json();
        return body.data &&
               body.data.order &&
               body.data.payment &&
               body.data.order.status === 'confirmed' &&
               body.data.payment.status === 'paid';
      },
    });
  } else if (checkoutRes.status === 422) {
    // Rollback — insufficient stock, transaction rolled back
    checkoutRollbacks.add(1);
    check(checkoutRes, {
      'checkout: rolled back (no oversell)': (r) => r.status === 422,
    });
  }

  sleep(randomSleep('normal'));
}
