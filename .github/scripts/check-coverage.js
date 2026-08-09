/**
 * Fails the build when PHP or JS coverage drops below the floors in
 * quality-thresholds.json.
 *
 * Run as a CI step by the workflow that runs after both test suites have
 * written their coverage reports. Kept out of the workflow so it can be
 * unit tested — see coverage.test.js.
 */

'use strict';

const fs = require( 'fs' );

const { parseClover, parseJestSummary, checkFloors } = require( './coverage' );

/**
 * Formats one checkFloors() result as a single readable output line.
 *
 * @param {string} suite  Suite label, e.g. 'PHP'.
 * @param {Object} result One entry from checkFloors().
 * @return {string} The line to print.
 */
function formatResult( suite, result ) {
	const { metric, pct, floor, ok } = result;
	if ( pct === null ) {
		return `${ suite } ${ metric }: n/a — not measured`;
	}
	return `${ suite } ${ metric }: ${ pct.toFixed( 2 ) }% (floor ${ floor }%) ${ ok ? 'OK' : 'FAIL' }`;
}

/**
 * Reads, parses and floor-checks one suite's coverage file, printing a line
 * per metric and a GitHub Actions error annotation per failure.
 *
 * @param {Object}   opts          Suite options.
 * @param {string}   opts.suite    Suite label, e.g. 'PHP'.
 * @param {string}   opts.filePath Path to the suite's coverage file.
 * @param {Object}   opts.fs       fs module (or a stand-in) to read with.
 * @param {Function} opts.parse    Parses the file's raw contents into actual coverage.
 * @param {Object}   opts.floors   Floors for this suite, from quality-thresholds.json.
 * @return {boolean} Whether every floor in this suite passed.
 */
function runSuite( { suite, filePath, fs: fsModule, parse, floors } ) {
	// A missing coverage file means the test step never ran, or ran and
	// silently failed to write its report. Skipping the suite in that case
	// would let a broken pipeline report green — exactly the false
	// assurance this check exists to prevent — so it is a hard failure.
	if ( ! fsModule.existsSync( filePath ) ) {
		console.error( `::error::${ suite } coverage file not found: ${ filePath }` );
		return false;
	}

	const actual = parse( fsModule.readFileSync( filePath, 'utf8' ) );
	const results = checkFloors( actual, floors );

	let ok = true;
	for ( const result of results ) {
		console.log( formatResult( suite, result ) );
		if ( ! result.ok ) {
			ok = false;
			console.error(
				`::error::${ suite } ${ result.metric } is ${ result.pct }% ` +
					`(floor ${ result.floor }%)`
			);
		}
	}

	return ok;
}

/**
 * Runs both suites' floor checks.
 *
 * @param {Object} opts          Run options.
 * @param {Object} opts.env      Environment variables to read config from.
 * @param {Object} opts.fs       fs module (or a stand-in) to read with.
 * @return {boolean} Whether every floor in both suites passed.
 */
function main( { env = process.env, fs: fsModule = fs } = {} ) {
	const {
		PHP_CLOVER = 'coverage/php-clover.xml',
		JS_SUMMARY = 'coverage/coverage-summary.json',
		THRESHOLDS = 'quality-thresholds.json',
	} = env;

	const thresholds = JSON.parse( fsModule.readFileSync( THRESHOLDS, 'utf8' ) );

	const phpOk = runSuite( {
		suite: 'PHP',
		filePath: PHP_CLOVER,
		fs: fsModule,
		parse: parseClover,
		floors: thresholds.coverage.php,
	} );

	const jsOk = runSuite( {
		suite: 'JS',
		filePath: JS_SUMMARY,
		fs: fsModule,
		parse: ( contents ) => parseJestSummary( JSON.parse( contents ) ),
		floors: thresholds.coverage.js,
	} );

	return phpOk && jsOk;
}

module.exports = { main };

/* istanbul ignore next -- CLI wiring, exercised by the integration test. */
if ( require.main === module ) {
	process.exit( main( {} ) ? 0 : 1 );
}
