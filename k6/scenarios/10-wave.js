/**
 * Scenario 10 — Wave Users (VUs 91-100)
 * 
 * Purpose: Random burst traffic on varied endpoints to create
 * unpredictable load. Tests system stability under mixed load.
 * 
 * These VUs pick random actions and execute them in bursts,
 * creating unpredictable traffic patterns that can reveal
 * unexpected bottlenecks or race conditions.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { randomInt, randomFloat, randomBool, randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

// Pool of possible actions
function randomAction() {
  const actions = [
    // Browse products
    () => http.get(`${BASE_URL}/products`),
    () => http.get(`${BASE_URL}/products?page=${randomInt(1, 5)}&per_page=20`),

    // Product details
    () => http.get(`${BASE_URL}/products/${randomInt(1, 50)}`),

    // Categories
    () => http.get(`${BASE_URL}/categories`),
    () => http.get(`${BASE_URL}/categories/${randomInt(1, 10)}`),
    () => http.get(`${BASE_URL}/categories/${randomInt(1, 10)}/products`),

    // Health endpoints
    () => http.get(`${BASE_URL}/health`),
    () => http.get(`${BASE_URL}/health/live`),
    () => http.get(`${BASE_URL}/health/ready`),

    // Auth (login attempts — causes rate limiting)
    () => http.post(`${BASE_URL}/auth/login`, JSON.stringify({
      email: `wave_${randomInt(1, 1000)}@test.com`,
      password: 'wrongpassword',
    }), {
      headers: { 'Content-Type': 'application/json' },
    }),
  ];

  return actions[randomInt(0, actions.length - 1)];
}

export function waveFlow() {
  // Execute 5-15 random actions with tiny delays between them
  const burstSize = randomInt(5, 15);

  for (let i = 0; i < burstSize; i++) {
    const action = randomAction();
    const res = action();

    // Just check it didn't crash (5xx = server error)
    check(res, {
      'wave: no server error': (r) => r.status < 500,
    });

    // Very short random delay between actions
    sleep(randomFloat(0.1, 0.5));
  }

  // Longer pause before next burst
  sleep(randomSleep('normal'));
}
