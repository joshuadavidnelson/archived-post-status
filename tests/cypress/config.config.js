const { defineConfig } = require('cypress')

module.exports = defineConfig({
	fixturesFolder: 'tests/cypress/fixtures',
	screenshotsFolder: 'tests/cypress/screenshots',
	downloadsFolder: 'tests/cypress/downloads',
	video: false,
	e2e: {
		baseUrl: 'http://localhost:8889',
		setupNodeEvents(on, config) {
			// implement node event listeners here
		},
		specPattern: 'tests/cypress/e2e/**/*.test.{js,jsx,ts,tsx}',
		supportFile: 'tests/cypress/support/e2e.js',
	},
})
