/**
 * k6 Helper — Synchronization Barrier (Rendezvous Point)
 * 
 * ⚠️ DOCUMENTATION ONLY — Not imported by any test file.
 * 
 * IMPORTANT: k6 does NOT support inter-VU synchronization barriers natively.
 * SharedArray is read-only and CANNOT be used for runtime coordination.
 * 
 * Our approach: Rely on k6's concurrent scenario initialization.
 * When multiple scenarios start at `startTime: '0s'`, k6 initializes ALL
 * their VUs before starting ANY of them. This means all VUs begin
 * executing within ~50ms of each other.
 * 
 * This is sufficient for the race condition test because:
 * - 20 VUs all hitting lockForUpdate() within 50ms will queue up on the
 *   database row lock — proving the locking mechanism works
 * - Without locking, all 20 would read stock=3 and oversell
 * - With locking, only 3 succeed and 17 get InsufficientStockException
 * 
 * For EXACT millisecond-level synchronization (should you need it):
 * Use JMeter's Synchronizing Timer instead of k6.
 * 
 * The actual synchronization is implemented directly in test-ecommerce.js
 * via the `startTime: '0s'` setting on scenarios 7 and 8.
 */

/**
 * Documents the synchronization strategy for a scenario.
 * This is a no-op function — the actual synchronization happens via
 * k6's `startTime: '0s'` scenario configuration.
 * 
 * Usage in scenarios that need tight timing:
 * ```
 * import { markSynchronized } from '../helpers/barrier.js';
 * markSynchronized('race-condition', 20);
 * ```
 * 
 * @param {string} scenarioName - Name of the synchronized scenario
 * @param {number} vuCount - Number of VUs in this synchronized group
 */
export function markSynchronized(scenarioName, vuCount) {
  // This function is a documentation marker only.
  // Actual synchronization is achieved via startTime: '0s' in the
  // scenario configuration within the main test options.
  // 
  // No runtime code is needed because k6 initializes all VUs before
  // executing any of them when startTime is the same across scenarios.
  return;
}

/**
 * Returns the synchronized scenario configuration for a given VU count.
 * Use this in the main test-ecommerce.js options to mark scenarios
 * that need tight synchronization.
 * 
 * @param {number} vus - Number of VUs for this scenario
 * @param {number} iterations - Iterations per VU
 * @param {string} execFunction - Name of the exported function to run
 * @returns {Object} k6 scenario configuration
 */
export function synchronizedScenario(vus, iterations, execFunction) {
  return {
    executor: 'per-vu-iterations',
    vus: vus,
    iterations: iterations,
    maxDuration: '2m',
    startTime: '0s',     // ← Key: starts at 0s (along with other sync'd scenarios)
    exec: execFunction,
    // Graceful stop ensures all VUs complete their current iteration
    gracefulStop: '5s',
  };
}

/**
 * Returns a normal (non-synchronized) scenario configuration
 * with a staggered ramp-up.
 * 
 * @param {number} vus - Number of VUs for this scenario
 * @param {string} execFunction - Name of the exported function to run
 * @param {string} startAfter - When to start (e.g., '5s', '10s')
 * @param {number} rampDuration - How long to ramp up (seconds)
 * @returns {Object} k6 scenario configuration
 */
export function normalScenario(vus, execFunction, startAfter = '5s', rampDuration = 15) {
  return {
    executor: 'ramping-vus',
    startVUs: 0,
    stages: [
      { duration: `${rampDuration}s`, target: vus },
      { duration: '60s', target: vus },
      { duration: '10s', target: 0 },
    ],
    startTime: startAfter,
    gracefulRampDown: '10s',
    exec: execFunction,
  };
}
