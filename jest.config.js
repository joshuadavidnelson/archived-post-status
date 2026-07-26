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
  verbose: true,
  transform: {
    '^.+\\.js$': 'babel-jest'
  }
};
