/**
 * Scenario 02 — Product Shoppers (VUs 11-20)
 * 
 * Purpose: Hit product detail pages — tests cache stampede prevention (Task 6).
 * 
 * Key behavior: All 10 VUs view the SAME product (id=1) repeatedly.
 * This creates a cache stampede scenario where:
 * - First request = cache MISS → triggers Cache::lock()
 * - Subsequent requests = cache HIT (or stale data while lock held)
 * 
 * If stampede prevention works: response times stay low (<10ms from cache)
 * If stampede prevention fails: multiple slow DB queries (>50ms each)
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';
import { randomSleep, randomInt } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

// Custom metric for product detail response times
export const productDetailDuration = new Trend('product_detail_duration');

export function productDetailFlow() {
  // ── Cache Stampede Test ──
  // All 10 VUs in this scenario hit product #1 repeatedly.
  // This tests Cache::lock() stampede prevention from Task 6.
  const stampedeProductId = 1;

  for (let i = 0; i < 3; i++) {
    const res = http.get(`${BASE_URL}/products/${stampedeProductId}`);
    check(res, {
      'detail: stampede product 200': (r) => r.status === 200,
      'detail: has product data': (r) => r.json('data') !== undefined,
    });

    // Track duration to verify cache is working
    // If cache works: all responses < 10ms
    // If cache fails: some responses > 50ms (DB queries)
    productDetailDuration.add(res.timings.duration);

    sleep(randomSleep('quick'));
  }

  // ── Random Product Viewing ──
  // Also view random products to test general caching
  for (let i = 0; i < 2; i++) {
    const randomProduct = randomInt(2, 50);
    const res = http.get(`${BASE_URL}/products/${randomProduct}`);
    check(res, {
      'detail: random product 200': (r) => r.status === 200,
    });
    sleep(randomSleep('quick'));
  }
}
