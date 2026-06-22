/**
 * Scenario 06 — Mixed Flow (VUs 51-60)
 * 
 * Purpose: Full user journey — Browse → View → Cart → Order.
 * End-to-end flow test covering ALL tasks simultaneously.
 * This is the most comprehensive scenario — it touches every layer.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { loginByVU, authHeaders } from '../helpers/auth.js';
import { randomInt, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

export const mixedFlowDuration = new Trend('mixed_flow_duration');

export function mixedFlow() {
  // ── Phase 1: Browse (Read-heavy, tests cache - Task 6) ──
  http.get(`${BASE_URL}/products`);
  http.get(`${BASE_URL}/categories`);
  http.get(`${BASE_URL}/products/${randomInt(1, 50)}`);

  sleep(randomSleep('quick'));

  // ── Phase 2: Login (Auth - Task 9 correlation ID) ──
  const { token } = loginByVU(__VU);
  const params = authHeaders(token);

  // Check that X-Correlation-ID header is present
  // (This verifies Task 9 - distributed tracing)
  sleep(randomSleep('quick'));

  // ── Phase 3: Cart (Write operations - Task 7) ──
  const addPayload = JSON.stringify({
    product_id: randomInt(1, 50),
    quantity: 1,
  });
  http.post(`${BASE_URL}/cart/items`, addPayload, params);

  sleep(randomSleep('quick'));

  // ── Phase 4: Order (Triggers async queues - Task 3) ──
  // IMPORTANT: PlaceOrderRequest requires shipping_address
  const orderRes = http.post(`${BASE_URL}/orders`, JSON.stringify({
    shipping_address: {
      name: 'k6 Mixed Test',
      street: '456 Mixed Blvd',
      city: 'Test City',
      country: 'US',
      zip: '10001',
      phone: '+1234567890',
    },
  }), params);
  mixedFlowDuration.add(orderRes.timings.duration);

  if (orderRes.status === 201) {
    // ── Phase 5: View order (Read after write) ──
    const orderId = orderRes.json('order.id');
    http.get(`${BASE_URL}/orders/${orderId}`, params);
  }

  sleep(randomSleep('pause'));
}
