// Complexity gate for the shipped JS. Ceilings live in quality-thresholds.json
// next to the coverage floors, so every threshold is tuned in one place.
// Only complexity rules run here; style is left to wp-scripts.
const sonarjs = require( 'eslint-plugin-sonarjs' );
const { js } = require( './quality-thresholds.json' );

const { cyclomatic, cognitive } = js.complexity;

module.exports = [
	// A global ignore, not one scoped to the block below: CI adds its own
	// --rule overrides for every linted file, and a test file reaching them
	// without sonarjs registered would abort the whole run.
	{
		ignores: [ 'assets/js/**/*.test.js' ],
	},
	{
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'script',
		},
		plugins: { sonarjs },
		rules: {
			complexity: [ 'error', cyclomatic ],
			'sonarjs/cognitive-complexity': [ 'error', cognitive ],
		},
	},
];
