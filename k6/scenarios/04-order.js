/**
 * Scenario 04 — Order Placers (VUs 31-40)
 * 
 * Purpose: Place orders — tests:
 * - Write contention and inventory decrement (Task 1)
 * - Async queue dispatching (Task 3)
 * - DB transactions and row locking
 * 
 * Key metric: Response time should be ~100ms (fast, async dispatch).
 * If response time is > 1s, jobs are running synchronously — BUG.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { loginByVU, authHeaders } from '../helpers/auth.js';
import { randomInt, randomQuantity, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

// Track order placement time — should be fast due to async queues
export const orderPlacementDuration = new Trend('order_placement_duration');

export function orderFlow() {
  // Step 1: Login
  const { token } = loginByVU(__VU);
  const params = authHeaders(token);
  sleep(randomSleep('quick'));

  // Step 2: Add a product to cart first
  const addPayload = JSON.stringify({
    product_id: randomInt(1, 50),
    quantity: 1,
  });

  let res = http.post(`${BASE_URL}/cart/items`, addPayload, params);
  check(res, {
    'order: add to cart 201': (r) => r.status === 201,
  });

  sleep(randomSleep('quick'));

  // Step 3: Place order (key operation)
  // This triggers: stock decrement, invoice job, notification job, analytics job
  // IMPORTANT: PlaceOrderRequest requires shipping_address validation
  const orderRes = http.post(`${BASE_URL}/orders`, JSON.stringify({
    shipping_address: {
      name: 'k6 Order Test',
      street: '123 k6 Street',
      city: 'Test City',
      country: 'US',
      zip: '10001',
      phone: '+1234567890',
    },
  }), params);
  check(orderRes, {
    'order: placed 201': (r) => r.status === 201,
    'order: has order id': (r) => {
      try {
        const body = JSON.parse(r.body);
        // The order object IS the data (not nested under data.order)
        // 'created()' passes the model directly to success() as $data
        return body?.data?.id !== undefined;
      } catch (e) { return false; }
    },
  });

  // Track duration — should be < 500ms if async queues work
  // If > 1s, jobs might be running synchronously (Task 3 regression)
  orderPlacementDuration.add(orderRes.timings.duration);

  // Step 4: Verify order appears in order list
  if (orderRes.status === 201) {
    const listRes = http.get(`${BASE_URL}/orders`, params);
    check(listRes, {
      'order: list 200': (r) => r.status === 200,
    });
  }

  sleep(randomSleep('pause'));
}
