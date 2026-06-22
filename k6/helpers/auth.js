/**
 * k6 Helper — Authentication
 * 
 * Handles user login and token management for k6 test scenarios.
 * Pre-registered users are stored in k6/data/users.json.
 * Each VU (1-100) has a dedicated user account.
 * 
 * IMPORTANT: The Laravel API wraps all responses in:
 *   { "success": true, "data": { ... }, "message": "..." }
 * So tokens are accessed via res.json('data.token'), not res.json('token').
 */

import http from 'k6/http';
import { check } from 'k6';

const BASE_URL = 'http://localhost:8080/api/v1';

/**
 * JSON request headers (no auth).
 * @returns {Object}
 */
export function jsonHeaders(extra = {}) {
  return {
    headers: { 'Content-Type': 'application/json', ...extra },
  };
}

/**
 * Authenticated request headers with Bearer token.
 * @param {string} token - Bearer token
 * @returns {Object}
 */
export function authHeaders(token) {
  return {
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json',
    },
  };
}

/**
 * Extract the token from a Laravel API response.
 * Laravel wraps responses in { success, data: { token, ... } }
 */
function extractToken(res) {
  try {
    const body = typeof res.body === 'string' ? JSON.parse(res.body) : res.body;
    return body?.data?.token || null;
  } catch (e) {
    return null;
  }
}

/**
 * Login a pre-created user by VU number.
 * Each VU has a dedicated user (vu1@test.com to vu100@test.com).
 * Uses unique X-Device-Name per VU to avoid token revocation collisions.
 * 
 * @param {number} vuId - VU number (1-100)
 * @returns {Object} { token, email }
 */
export function loginByVU(vuId) {
  const email = `vu${vuId}@test.com`;
  const password = 'password123';
  const deviceName = `k6_vu_${vuId}`;

  const res = http.post(`${BASE_URL}/auth/login`, JSON.stringify({
    email: email,
    password: password,
  }), jsonHeaders({
    'X-Device-Name': deviceName,
  }));

  check(res, {
    'login successful': (r) => r.status === 200,
  });

  if (res.status !== 200) {
    // User might not exist — try registering
    return registerUser(email, password);
  }

  return {
    token: extractToken(res),
    email: email,
  };
}

/**
 * Register a new user and return their token.
 * Used as fallback if pre-created users don't exist.
 * 
 * @param {string} email
 * @param {string} password
 * @returns {Object} { token, email }
 */
export function registerUser(email, password) {
  const res = http.post(`${BASE_URL}/auth/register`, JSON.stringify({
    name: email.split('@')[0],
    email: email,
    password: password,
    password_confirmation: password,
  }), jsonHeaders());

  if (res.status === 201) {
    return {
      token: extractToken(res),
      email: email,
    };
  }

  // If register fails (user already exists), try login again
  const loginRes = http.post(`${BASE_URL}/auth/login`, JSON.stringify({
    email: email,
    password: password,
  }), jsonHeaders({
    'X-Device-Name': 'register_fallback_' + email.split('@')[0],
  }));

  return {
    token: extractToken(loginRes),
    email: email,
  };
}

/**
 * Login as admin user.
 * Uses unique X-Device-Name per VU so 10 admin VUs don't revoke each other's tokens.
 * @returns {Object} { token, email }
 */
export function loginAsAdmin() {
  const email = 'admin@ecommerce.test';
  const password = 'password';
  // Each VU gets its own device name to avoid revoking tokens from other VUs
  const deviceName = `admin_vu_${__VU}`;

  const res = http.post(`${BASE_URL}/auth/login`, JSON.stringify({
    email: email,
    password: password,
  }), jsonHeaders({
    'X-Device-Name': deviceName,
  }));

  check(res, {
    'admin login successful': (r) => r.status === 200,
  });

  return {
    token: extractToken(res),
    email: email,
  };
}
