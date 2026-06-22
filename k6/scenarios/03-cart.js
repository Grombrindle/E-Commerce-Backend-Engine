/**
 * Scenario 03 — Cart Users (VUs 21-30)
 * 
 * Purpose: Test authenticated cart operations — mixed reads/writes.
 * Tests: Database write contention (Task 7), request rate limiting (Task 2).
 * 
 * Flow: Login → Add items → View cart → Clear cart
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { loginByVU, authHeaders } from '../helpers/auth.js';
import { randomInt, randomQuantity, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

export function cartFlow() {
  // Step 1: Login
  const { token } = loginByVU(__VU);
  const params = authHeaders(token);
  sleep(randomSleep('quick'));

  // Step 2: Add 2-4 different products to cart
  const itemsToAdd = randomInt(2, 4);
  for (let i = 0; i < itemsToAdd; i++) {
    const payload = JSON.stringify({
      product_id: randomInt(1, 50),
      quantity: randomQuantity(),
    });

    const res = http.post(`${BASE_URL}/cart/items`, payload, params);
    check(res, {
      'cart: add item 201': (r) => r.status === 201,
      'cart: item has id': (r) => r.json('data') !== undefined,
    });

    sleep(randomSleep('quick'));
  }

  // Step 3: View cart summary (tests cart read performance)
  const summaryRes = http.get(`${BASE_URL}/cart/summary`, params);
  check(summaryRes, {
    'cart: summary 200': (r) => r.status === 200,
  });

  sleep(randomSleep('normal'));

  // Step 4: Optionally clear cart (50% chance)
  if (Math.random() > 0.5) {
    const clearRes = http.del(`${BASE_URL}/cart`, null, params);
    check(clearRes, {
      'cart: clear 200': (r) => r.status === 200,
    });
  }
}
