// Complexity gate for the shipped JS. Ceilings live in quality-thresholds.json
// next to the coverage floors, so every threshold is tuned in one place.
// Only complexity rules run here; style is left to wp-scripts.
const sonarjs = require( 'eslint-plugin-sonarjs' );
const { js } = require( './quality-thresholds.json' );

const { cyclomatic, cognitive } = js.complexity;

module.exports = [
	{
		files: [ 'assets/js/**/*.js' ],
		ignores: [ 'assets/js/**/*.test.js' ],
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
