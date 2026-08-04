/**
 * Parses PHP (clover) and JS (jest json-summary) coverage reports into a
 * common shape, and checks that shape against the floors in
 * quality-thresholds.json.
 *
 * Used by check-coverage.js — split out so the parsing and floor logic can
 * be unit tested without touching the filesystem or process.exit().
 */

'use strict';

/**
 * Builds a Metric, or null when nothing was measured.
 *
 * Xdebug line coverage never populates clover's conditionals attributes, so
 * total === 0 is the normal case for branches in this repo, not an error.
 * Returning a 0% pct for an unmeasured metric would read as a catastrophic
 * regression when it actually means "no signal either way" — floor checks
 * must treat it as a pass, not a failure.
 *
 * Percentages are recomputed here rather than taken from Jest, which floors
 * where this rounds, so the two can disagree by a hundredth at a floor
 * boundary. Ours is never the stricter of the two, and Jest enforces its own
 * thresholds earlier in the same job, so the lenient case cannot reach a
 * green build today. Splitting those steps apart would change that.
 *
 * @param {number} covered Covered count.
 * @param {number} total   Total count.
 * @return {{covered: number, total: number, pct: number}|null} The metric, or null.
 */
function toMetric( covered, total ) {
	if ( ! total ) {
		return null;
	}
	return {
		covered,
		total,
		pct: Math.round( ( covered / total ) * 10000 ) / 100,
	};
}

/**
 * Parses a PHPUnit clover XML report into project-level coverage metrics.
 *
 * Clover nests a <metrics> element inside every <file> and <class>, and
 * those come before the project total in document order — so this cannot
 * just take the first <metrics> found after <project>. Instead it relies on
 * "files" being an attribute only the project-level total carries (it is a
 * count of files in the report, which no file- or class-level element has),
 * which finds the right element regardless of how many file/class metrics
 * precede it.
 *
 * @param {string} xml Clover XML report contents.
 * @return {{lines: Object|null, methods: Object|null, branches: Object|null}} Project coverage.
 */
function parseClover( xml ) {
	const projectIndex = xml.indexOf( '<project' );
	if ( projectIndex === -1 ) {
		throw new Error( 'parseClover: no <project> element found in clover XML' );
	}

	const match = /<metrics\s+[^>]*\bfiles="[^"]*"[^>]*\/>/.exec( xml.slice( projectIndex ) );
	if ( ! match ) {
		throw new Error( 'parseClover: no project-level <metrics> element found' );
	}

	const attrs = {};
	const attrPattern = /(\w+)="([^"]*)"/g;
	let attrMatch;
	while ( ( attrMatch = attrPattern.exec( match[ 0 ] ) ) !== null ) {
		attrs[ attrMatch[ 1 ] ] = Number( attrMatch[ 2 ] );
	}

	return {
		lines: toMetric( attrs.coveredstatements, attrs.statements ),
		methods: toMetric( attrs.coveredmethods, attrs.methods ),
		branches: toMetric( attrs.coveredconditionals, attrs.conditionals ),
	};
}

/**
 * Parses the "total" section of Jest's json-summary coverage report.
 *
 * Jest's own pct is untrustworthy — it is the string "Unknown" for a 0-total
 * metric — so this recomputes pct from covered/total the same way
 * parseClover does, which also means both suites round identically.
 * branchesTrue is Jest-internal (function-branch tracking) and is ignored.
 *
 * @param {Object} summary Parsed coverage-summary.json.
 * @return {{statements: Object|null, branches: Object|null, functions: Object|null, lines: Object|null}} Coverage.
 */
function parseJestSummary( summary ) {
	const total = summary.total || {};
	const metric = ( key ) => {
		const found = total[ key ];
		return found ? toMetric( found.covered, found.total ) : null;
	};

	return {
		statements: metric( 'statements' ),
		branches: metric( 'branches' ),
		functions: metric( 'functions' ),
		lines: metric( 'lines' ),
	};
}

/**
 * Checks parsed coverage against a set of floors.
 *
 * A metric that was not measured (null) is never a failure — not measuring
 * branches, for example, must not fail the build. A floor key with no
 * matching entry in actual is a configuration error, not a pass: silently
 * ignoring it would let a typo in quality-thresholds.json disable a floor
 * with no signal that anything is wrong.
 *
 * @param {Object} actual Parsed coverage, as returned by parseClover/parseJestSummary.
 * @param {Object} floors Floor percentages keyed by metric name.
 * @return {Array<{metric: string, pct: number|null, floor: number, ok: boolean}>} One entry per floor key.
 */
function checkFloors( actual, floors ) {
	return Object.keys( floors ).map( ( metric ) => {
		if ( ! ( metric in actual ) ) {
			throw new Error( `checkFloors: "${ metric }" is not a known coverage metric` );
		}

		const floor = floors[ metric ];
		const data = actual[ metric ];

		if ( data === null ) {
			return { metric, pct: null, floor, ok: true };
		}

		return { metric, pct: data.pct, floor, ok: data.pct >= floor };
	} );
}

module.exports = { parseClover, parseJestSummary, checkFloors };
