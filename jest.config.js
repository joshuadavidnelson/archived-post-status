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
    'html'
  ],
  collectCoverage: false,
  // Applies whenever coverage is collected (npm run test:coverage, CI).
  // The shipped sources are small and fully exercised — a drop below the
  // floor means a suite stopped importing the real module.
  coverageThreshold: {
    global: {
      statements: 90,
      branches: 85,
      functions: 90,
      lines: 90
    }
  },
  verbose: true,
  transform: {
    '^.+\\.js$': 'babel-jest'
  }
};
