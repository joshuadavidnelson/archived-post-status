// Floors live in quality-thresholds.json alongside the PHP ones, so both
// languages are tuned in one place and cannot drift apart.
const { coverage } = require( './quality-thresholds.json' );

module.exports = {
  testEnvironment: 'jsdom',
  testMatch: [
    '**/assets/js/**/*.test.js'
  ],
  collectCoverageFrom: [
    'assets/js/**/*.js',
    '!assets/js/**/*.test.js'
  ],
  coverageDirectory: 'coverage',
  coverageReporters: [
    'text',
    'lcov',
    'html',
    // Machine-readable totals for the PR coverage report.
    'json-summary'
  ],
  collectCoverage: false,
  // Applies whenever coverage is collected (npm run test:coverage, CI).
  // The floor holds coverage steady over time and catches a suite that has
  // stopped importing the real module. It sits a few points below current
  // so changes that legitimately add uncovered lines are not blocked; the
  // PR report shows the delta against base for anything smaller than that.
  coverageThreshold: {
    global: coverage.js
  },
  verbose: true,
  transform: {
    '^.+\\.js$': 'babel-jest'
  }
};
