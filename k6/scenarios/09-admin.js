/**
 * Scenario 09 — Admin Users (VUs 81-90)
 * 
 * Purpose: Test admin CRUD operations — inventory management, order management.
 * Tests: Admin middleware, write operations on inventory and orders.
 * 
 * Note: These VUs share the SAME admin account (admin@ecommerce.com).
 * The login step is per-VU but all use the same admin credentials.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { loginAsAdmin, authHeaders } from '../helpers/auth.js';
import { randomInt, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

export function adminFlow() {
  // Step 1: Login as admin (shared admin account)
  const { token } = loginAsAdmin();
  const params = authHeaders(token);
  sleep(randomSleep('quick'));

  // Step 2: View admin dashboard data
  let res = http.get(`${BASE_URL}/admin/orders/stats`, params);
  check(res, {
    'admin: order stats 200': (r) => r.status === 200,
  });

  sleep(randomSleep('quick'));

  res = http.get(`${BASE_URL}/admin/inventory/low-stock`, params);
  check(res, {
    'admin: low stock 200': (r) => r.status === 200,
  });

  sleep(randomSleep('quick'));

  // Step 3: List all inventory
  res = http.get(`${BASE_URL}/admin/inventory`, params);
  check(res, {
    'admin: inventory list 200': (r) => r.status === 200,
  });

  sleep(randomSleep('normal'));

  // Step 4: Update inventory for a random product (write operation)
  const productId = randomInt(1, 20);
  const newStock = randomInt(10, 200);
  res = http.put(`${BASE_URL}/admin/inventory/${productId}`, JSON.stringify({
    quantity: newStock,
  }), params);

  check(res, {
    'admin: inventory update 200': (r) => r.status === 200,
  });

  sleep(randomSleep('quick'));

  // Step 5: List products (admin CRUD)
  res = http.get(`${BASE_URL}/admin/products`, params);
  check(res, {
    'admin: products list 200': (r) => r.status === 200,
  });

  sleep(randomSleep('normal'));
}
