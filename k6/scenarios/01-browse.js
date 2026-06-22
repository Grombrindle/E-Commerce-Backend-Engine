/**
 * Scenario 01 — Browsers (VUs 1-10)
 * 
 * Purpose: Test read-heavy load on public endpoints.
 * Tests Redis caching layer (Task 6) and general read performance.
 * 
 * Actions:
 * - View categories list
 * - View products by category
 * - Browse paginated product listings
 * - Random sleep between actions to simulate real user behavior
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { randomInt, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

export function browseFlow() {
  // Step 1: View all categories (tests basic read endpoint)
  let res = http.get(`${BASE_URL}/categories`);
  check(res, {
    'browse: categories status 200': (r) => r.status === 200,
    'browse: categories has data': (r) => r.json('data') !== undefined,
  });

  sleep(randomSleep('quick'));

  // Step 2: View a single category with its products
  const catId = randomInt(1, 10);
  res = http.get(`${BASE_URL}/categories/${catId}`);
  check(res, {
    'browse: single category 200': (r) => r.status === 200,
  });

  sleep(randomSleep('quick'));

  // Step 3: Browse products by category
  res = http.get(`${BASE_URL}/categories/${catId}/products`);
  check(res, {
    'browse: category products 200': (r) => r.status === 200,
  });

  sleep(randomSleep('normal'));

  // Step 4: Browse paginated product list
  const page = randomInt(1, 5);
  res = http.get(`${BASE_URL}/products?page=${page}&per_page=20`);
  check(res, {
    'browse: product list 200': (r) => r.status === 200,
    'browse: products has data': (r) => r.json('data') !== undefined,
  });

  sleep(randomSleep('normal'));
}
