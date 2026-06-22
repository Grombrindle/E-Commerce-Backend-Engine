/**
 * k6 Helper — Random Utilities
 * 
 * Provides random number generation utilities for k6 test scenarios.
 * All scenarios use these to create varied, realistic load patterns.
 */

/**
 * Returns a random integer between min and max (inclusive).
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
export function randomInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

/**
 * Returns a random float between min and max.
 * @param {number} min
 * @param {number} max
 * @returns {number}
 */
export function randomFloat(min, max) {
  return Math.random() * (max - min) + min;
}

/**
 * Returns a random element from an array.
 * @param {Array} arr
 * @returns {*}
 */
export function randomElement(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

/**
 * Returns a random boolean with given probability of being true.
 * @param {number} probabilityTrue - 0.0 to 1.0
 * @returns {boolean}
 */
export function randomBool(probabilityTrue = 0.5) {
  return Math.random() < probabilityTrue;
}

/**
 * Returns a random product ID between 1 and maxProduct.
 * Defaults to 50 products available in the database.
 * @param {number} maxProduct
 * @returns {number}
 */
export function randomProductId(maxProduct = 50) {
  return randomInt(1, maxProduct);
}

/**
 * Returns a random quantity (1-3) for cart operations.
 * @returns {number}
 */
export function randomQuantity() {
  return randomInt(1, 3);
}

/**
 * Returns a random sleep duration in seconds.
 * Mimics real user think time between actions.
 * @param {string} action - 'quick', 'normal', 'pause'
 * @returns {number} seconds
 */
export function randomSleep(action = 'normal') {
  switch (action) {
    case 'quick':  return randomFloat(0.1, 0.5);
    case 'normal': return randomFloat(0.5, 3.0);
    case 'pause':  return randomFloat(3.0, 8.0);
    default:       return randomFloat(0.5, 3.0);
  }
}
