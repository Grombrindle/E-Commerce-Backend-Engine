/**
 * Scenario 05 — Auth Users (VUs 41-50)
 * 
 * Purpose: Test authentication system — rate limiting (Task 2).
 * Key test: Rapid repeated logins should trigger 429 Too Many Requests
 * after ~60 requests per minute (RateLimiter 'api' configuration).
 * 
 * Also tests: Register, Login, Profile access, Logout flow.
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter } from 'k6/metrics';
import { jsonHeaders, authHeaders } from '../helpers/auth.js';
import { randomSleep } from '../helpers/random.js';

const BASE_URL = 'http://localhost:8080/api/v1';

// Track rate limit hits
export const rateLimitHits = new Counter('rate_limit_hits');

export function authFlow() {
  const email = `vu${__VU}@test.com`;
  const password = 'password123';

  // Step 1: Login (should succeed)
  // IMPORTANT: Token is nested under "data.token" in Laravel responses
  let res = http.post(`${BASE_URL}/auth/login`, JSON.stringify({
    email: email,
    password: password,
  }), jsonHeaders());

  check(res, {
    'auth: login 200': (r) => r.status === 200,
  });

  // Token is inside data.token in Laravel's wrapped response
  const loginBody = JSON.parse(res.body);
  let token = loginBody?.data?.token;
  const params = authHeaders(token);

  sleep(randomSleep('quick'));

  // Step 2: Access authenticated profile endpoint
  res = http.get(`${BASE_URL}/auth/me`, params);
  check(res, {
    'auth: profile 200': (r) => r.status === 200,
  });

  sleep(randomSleep('quick'));

  // Step 3: Rapid repeated logins (rate limit test - Task 2)
  // The RateLimiter 'api' is configured at 60 req/min per user/IP.
  // Since all VUs share the same localhost IP, after ~60 total attempts
  // across all VUs, subsequent requests should get 429.
  for (let i = 0; i < 10; i++) {
    res = http.post(`${BASE_URL}/auth/login`, JSON.stringify({
      email: email,
      password: password,
    }), jsonHeaders());

    if (res.status === 429) {
      rateLimitHits.add(1);
      // Rate limiting working — log was tracked
    } else if (res.status === 200) {
      // Update token on success (extract from data.token)
      const body = JSON.parse(res.body);
      token = body?.data?.token || token;
    }

    // Very short sleep to maximize request rate
    sleep(0.1);
  }    // Step 4: Logout — recreate params with latest token (step 3 may have updated it)
  const latestParams = authHeaders(token);
  res = http.post(`${BASE_URL}/auth/logout`, null, latestParams);
  check(res, {
    'auth: logout 200': (r) => r.status === 200,
  });
}
