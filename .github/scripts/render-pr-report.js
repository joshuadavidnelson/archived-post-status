/**
 * Renders the combined "PR Report" sticky comment: PHPMetrics deltas plus
 * coverage deltas in one comment.
 *
 * Called by the report job in .github/workflows/integrate.yml, which
 * generates the metrics summaries and coverage reports first. Kept out of
 * the workflow so it can be unit tested — see render-pr-report.test.js.
 */

'use strict';

// quality-thresholds.json only stores numbers (metrics.max); this catalogue
// is the schema knowledge of where each thresholded metric lives in a
// PHPMetrics summary and what to call it in the table. A key present in
// metrics.max but missing here is treated as a config error, not silently
// dropped — see the lookup in renderMetricsSection().
const CATALOGUE = {
	critical: { section: 'Violations', label: 'Violations: critical' },
	error: { section: 'Violations', label: 'Violations: error' },
	avgCyclomaticComplexityByClass: { section: 'Complexity', label: 'Avg cyclomatic complexity / class' },
	logicalLinesByMethod: { section: 'LOC', label: 'Logical lines per method' },
	lackCohesionOfMethods: { section: 'OOP', label: 'Lack of cohesion of methods' },
	avgEfferentCoupling: { section: 'Coupling', label: 'Avg efferent coupling' },
};

// Every field a suite can report. A field neither side measured is left out
// of the table entirely rather than shown as `n/a` — PHP branch coverage is
// permanently unmeasured (Xdebug does not populate clover's conditionals),
// and a row that can only ever say "no data" is noise.
const COVERAGE_SUITES = [
	[ 'php', 'PHP', [ [ 'lines', 'Lines' ], [ 'methods', 'Methods' ], [ 'branches', 'Branches' ] ] ],
	[ 'js', 'JS', [ [ 'statements', 'Statements' ], [ 'branches', 'Branches' ], [ 'functions', 'Functions' ], [ 'lines', 'Lines' ] ] ],
];

/**
 * Reads one metric, treating anything non-numeric as absent.
 *
 * @param {Object} report  A PHPMetrics summary.
 * @param {string} section Top-level group, e.g. 'Complexity'.
 * @param {string} key     Metric name within the group.
 * @return {number|null} The value, or null when the report omits it.
 */
function value( report, section, key ) {
	const found = ( ( report && report[ section ] ) || {} )[ key ];
	return typeof found === 'number' ? found : null;
}

/**
 * Formats a number for the table: integers bare, everything else to 2dp.
 *
 * @param {number|null} n The value.
 * @return {string} The rendered cell.
 */
function format( n ) {
	if ( n === null || n === undefined ) {
		return 'n/a';
	}
	return Number.isInteger( n ) ? String( n ) : n.toFixed( 2 );
}

/**
 * The change between two values, measured on what the table actually shows.
 *
 * Rounding the raw values and rounding the difference are not the same
 * operation, so computing the delta from the inputs lets it contradict the
 * cells beside it — 6.004 and 6.006 render as 6.00 and 6.01 while their raw
 * difference rounds to zero. Diffing the displayed numbers keeps the three
 * columns telling one story.
 *
 * @param {number} before Base value.
 * @param {number} after  PR value.
 * @return {number} The change, to the same precision as the cells.
 */
function shownChange( before, after ) {
	return Number( ( Number( format( after ) ) - Number( format( before ) ) ).toFixed( 2 ) );
}

/**
 * Formats a coverage Metric as a percentage cell.
 *
 * `null` means the suite doesn't measure this (e.g. Xdebug never populates
 * PHP branch coverage) — that's a normal, expected state, not a zero.
 *
 * @param {{pct: number}|null} metric The coverage metric, or null if unmeasured.
 * @return {string} The rendered cell.
 */
function pct( metric ) {
	return metric === null || metric === undefined ? 'n/a' : `${ format( metric.pct ) }%`;
}

/**
 * Whether the base summary can be diffed against, for the no-baseline guard.
 *
 * @param {Object} base A PHPMetrics summary for the base branch.
 * @return {boolean} True when the base declares classes to measure.
 */
function hasMetricsBaseline( base ) {
	// Every metric PHPMetrics summarises derives from classes, so a base with
	// none reports 0 across the board. Diffing against that would render the
	// whole table as a regression from zero.
	return value( base, 'OOP', 'classes' ) > 0;
}

/**
 * Whether a coverage report has any real data, used for the no-baseline guard.
 *
 * @param {Object} report A `{ php, js }` coverage report.
 * @return {boolean} True when at least one suite metric was measured.
 */
function hasCoverage( report ) {
	if ( ! report ) {
		return false;
	}
	const values = [ ...Object.values( report.php || {} ), ...Object.values( report.js || {} ) ];
	return values.some( ( m ) => m !== null && m !== undefined );
}

/**
 * Builds the Code Metrics section: PHPMetrics values against their
 * configured ceilings.
 *
 * @param {Object} input     Render input.
 * @param {Object} input.head PHPMetrics summary for the PR branch.
 * @param {Object} input.base PHPMetrics summary for the base branch.
 * @param {Object} input.max  quality-thresholds.json's `metrics.max`.
 * @return {string} Markdown for the section, including its own headline.
 */
function renderMetricsSection( { head, base, max } ) {
	// Every metric PHPMetrics summarises is derived from classes, so a base
	// with none reports 0 across the board — even neutral counts. Diffing
	// against that would render the whole table as a regression from zero,
	// so fall back to reporting this branch on its own.
	const comparable = hasMetricsBaseline( base );

	const rows = [];
	let regressions = 0;
	let breaches = 0;

	for ( const key of Object.keys( max ) ) {
		const entry = CATALOGUE[ key ];
		if ( ! entry ) {
			throw new Error(
				`render-pr-report: "${ key }" is in quality-thresholds.json's metrics.max but has no entry in CATALOGUE (render-pr-report.js) — add one.`
			);
		}
		const { section, label } = entry;
		const ceiling = max[ key ];

		const before = value( base, section, key );
		const after = value( head, section, key );
		if ( before === null && after === null ) {
			continue;
		}

		// Distinct from the Δ column below: a metric can regress against the
		// base while still sitting under its ceiling, and both need to show.
		const breached = after !== null && after > ceiling;
		if ( breached ) {
			breaches++;
		}
		const maxCell = after === null ? format( ceiling ) : `${ format( ceiling ) } ${ breached ? '❌' : '✅' }`;

		if ( ! comparable ) {
			rows.push( `| ${ label } | ${ format( after ) } | ${ maxCell } |` );
			continue;
		}

		let delta = '—';
		if ( before !== null && after !== null ) {
			const change = shownChange( before, after );
			if ( change !== 0 ) {
				// Every thresholded metric is a ceiling now, so worse always
				// means it went up — there's no lowerIsBetter flag to check.
				const worse = change > 0;
				if ( worse ) {
					regressions++;
				}
				delta = `${ worse ? '⚠️ ' : '' }${ change > 0 ? '+' : '-' }${ format( Math.abs( change ) ) }`;
			}
		}
		rows.push( `| ${ label } | ${ format( before ) } | ${ format( after ) } | ${ delta } | ${ maxCell } |` );
	}

	const icon = breaches > 0 ? '❌' : comparable ? ( regressions > 0 ? '⚠️' : '✅' ) : 'ℹ️';
	const baselineSentence = comparable
		? regressions === 0
			? 'No metric moved in the wrong direction.'
			: `${ regressions } metric${ regressions === 1 ? '' : 's' } moved in the wrong direction.`
		: 'Base declares no classes, so there is no class-based baseline to diff against. Showing this branch on its own.';
	const thresholdSentence =
		breaches === 0 ? 'All within threshold.' : `${ breaches } metric${ breaches === 1 ? '' : 's' } breached threshold.`;

	return [
		`${ icon } ${ baselineSentence } ${ thresholdSentence }`,
		'',
		'### Code Metrics',
		...( comparable
			? [ '| Metric | Base | PR | Δ | Max |', '| --- | ---: | ---: | ---: | ---: |' ]
			: [ '| Metric | PR | Max |', '| --- | ---: | ---: |' ] ),
		...rows,
	].join( '\n' );
}

/**
 * Rejects a floor this renderer would not apply to anything.
 *
 * An unrecognised suite or metric key renders as "no floor configured",
 * which is indistinguishable from a deliberate decision not to gate it. A
 * typo in quality-thresholds.json would then quietly stop reporting a floor
 * on a metric that has one, so it fails loudly instead.
 *
 * @param {Object} floors quality-thresholds.json's `coverage`.
 * @throws {Error} When a suite or metric key is not one this renderer knows.
 */
function assertKnownFloors( floors ) {
	for ( const [ suiteKey, suiteFloors ] of Object.entries( floors || {} ) ) {
		const suite = COVERAGE_SUITES.find( ( [ key ] ) => key === suiteKey );
		if ( ! suite ) {
			throw new Error(
				`render-pr-report: "${ suiteKey }" is not a coverage suite (expected ${ COVERAGE_SUITES.map( ( [ key ] ) => key ).join( ', ' ) }).`
			);
		}

		const known = suite[ 2 ].map( ( [ key ] ) => key );
		for ( const metricKey of Object.keys( suiteFloors || {} ) ) {
			if ( ! known.includes( metricKey ) ) {
				throw new Error(
					`render-pr-report: "${ suiteKey }.${ metricKey }" is not a metric this suite reports (expected ${ known.join( ', ' ) }).`
				);
			}
		}
	}
}

/**
 * Builds the Coverage section: per-suite coverage against its configured floor.
 *
 * @param {Object} input        Render input.
 * @param {Object} input.head   `{ php, js }` coverage for the PR branch.
 * @param {Object} input.base   `{ php, js }` coverage for the base branch.
 * @param {Object} input.floors quality-thresholds.json's `coverage`.
 * @return {string} Markdown for the section.
 */
function renderCoverageSection( { head, base, floors } ) {
	assertKnownFloors( floors );

	// Mirrors the metrics no-baseline guard: a base with no coverage data at
	// all (the workflow skipped it, or this is the first run) would
	// otherwise render every row as a drop to 0%.
	const comparable = hasCoverage( base );

	const rows = [];
	for ( const [ suiteKey, suiteLabel, fields ] of COVERAGE_SUITES ) {
		const suiteFloors = ( floors && floors[ suiteKey ] ) || {};

		for ( const [ metricKey, metricLabel ] of fields ) {
			const before = ( ( base && base[ suiteKey ] ) || {} )[ metricKey ] ?? null;
			const after = ( ( head && head[ suiteKey ] ) || {} )[ metricKey ] ?? null;
			const floor = suiteFloors[ metricKey ];

			// Neither side measured it, so there is nothing to report. Matches
			// how the metrics table drops a metric absent from both summaries.
			if ( before === null && after === null ) {
				continue;
			}

			// An unmeasured metric (null) has nothing to gate on, and a
			// metric with no configured floor isn't gated either — both
			// render as "—", never a false ❌.
			let floorCell = '—';
			if ( after !== null && typeof floor === 'number' ) {
				floorCell = `${ format( floor ) }% ${ after.pct >= floor ? '✅' : '❌' }`;
			}

			if ( ! comparable ) {
				rows.push( `| ${ suiteLabel } | ${ metricLabel } | ${ pct( after ) } | ${ floorCell } |` );
				continue;
			}

			let delta = '—';
			if ( before !== null && after !== null ) {
				const change = shownChange( before.pct, after.pct );
				if ( change !== 0 ) {
					delta = `${ change > 0 ? '+' : '-' }${ format( Math.abs( change ) ) }%`;
				}
			}
			rows.push( `| ${ suiteLabel } | ${ metricLabel } | ${ pct( before ) } | ${ pct( after ) } | ${ delta } | ${ floorCell } |` );
		}
	}

	// Dropping unmeasured rows can empty the table completely, which would
	// leave a heading over nothing but column names.
	if ( ! rows.length ) {
		return '';
	}

	return [
		'### Coverage',
		...( comparable
			? []
			: [ "ℹ️ No coverage data for the base branch. Showing the PR's numbers alone.", '' ] ),
		...( comparable
			? [ '| Suite | Metric | Base | PR | Δ | Floor |', '| --- | --- | ---: | ---: | ---: | ---: |' ]
			: [ '| Suite | Metric | PR | Floor |', '| --- | --- | ---: | ---: |' ] ),
		...rows,
	].join( '\n' );
}

/**
 * Assembles the full PR Report comment from whichever sections have data.
 *
 * @param {Object}      input          Render input.
 * @param {Object|null} input.metrics  `{ head, base, max }` for renderMetricsSection, or null to omit the section.
 * @param {Object|null} input.coverage `{ head, base, floors }` for renderCoverageSection, or null to omit the section.
 * @param {string}      input.headSha  Full SHA of the PR head.
 * @param {string}      input.baseSha  Full SHA of the base.
 * @param {string}      input.runUrl   Link to the workflow run holding the metrics artifact.
 * @return {string} Markdown body.
 */
function renderPrReport( { metrics, coverage, headSha, baseSha, runUrl } ) {
	const shortHead = headSha.slice( 0, 7 );
	const shortBase = baseSha.slice( 0, 7 );

	const sections = [];
	if ( metrics ) {
		sections.push( renderMetricsSection( metrics ), '' );
	}
	if ( coverage ) {
		const section = renderCoverageSection( coverage );
		if ( section ) {
			sections.push( section, '' );
		}
	}

	// Only claim a comparison the sections can actually back up. When no
	// section found a usable baseline they each fall back to showing the PR
	// alone, and a "base → PR" header would contradict their own ℹ️ notes.
	const comparable =
		( metrics ? hasMetricsBaseline( metrics.base ) : false ) ||
		( coverage ? hasCoverage( coverage.base ) : false );

	return [
		'## 📊 PR Report',
		'',
		comparable
			? `\`${ shortBase }\` (base) → \`${ shortHead }\` (PR)`
			: `\`${ shortHead }\` (PR)`,
		'',
		...sections,
		`<sub>PHPMetrics summary — the full JSON is attached to [the Integrate run](${ runUrl }) as \`phpmetrics-summary\`.</sub>`,
	].join( '\n' );
}

module.exports = { renderMetricsSection, renderCoverageSection, renderPrReport };

/* istanbul ignore next -- CLI wiring, exercised by the integration test. */
if ( require.main === module ) {
	const fs = require( 'fs' );

	const {
		HEAD_SUMMARY = 'reports/metrics-summary.json',
		BASE_SUMMARY = 'reports/base-summary.json',
		PHP_CLOVER,
		PHP_BASE_CLOVER,
		JS_SUMMARY,
		JS_BASE_SUMMARY,
		THRESHOLDS = 'quality-thresholds.json',
		OUTPUT = 'reports/pr-report.md',
		HEAD_SHA,
		BASE_SHA,
		RUN_URL,
	} = process.env;

	for ( const [ name, set ] of Object.entries( { HEAD_SHA, BASE_SHA, RUN_URL } ) ) {
		if ( ! set ) {
			console.error( `::error::${ name } is not set` );
			process.exit( 1 );
		}
	}

	const readJson = ( filePath ) => JSON.parse( fs.readFileSync( filePath, 'utf8' ) );
	// An optional data file (no base coverage yet, no base summary on a
	// brand new metric) degrades its section or column rather than crashing.
	const readJsonIfExists = ( filePath ) => ( filePath && fs.existsSync( filePath ) ? readJson( filePath ) : null );

	const thresholds = readJson( THRESHOLDS );

	const headSummary = readJsonIfExists( HEAD_SUMMARY );
	const baseSummary = readJsonIfExists( BASE_SUMMARY );
	const metrics = headSummary ? { head: headSummary, base: baseSummary || {}, max: thresholds.metrics.max } : null;

	// coverage.js turns the raw clover XML / jest summary JSON into the
	// { lines, methods, branches } / { statements, branches, functions, lines }
	// shapes renderCoverageSection expects; it's the other half of this
	// refactor and owns that parsing so it isn't duplicated here.
	let coverage = null;
	if ( PHP_CLOVER || JS_SUMMARY ) {
		const { parseClover, parseJestSummary } = require( './coverage' );

		const build = ( cloverPath, summaryPath ) => ( {
			php: cloverPath && fs.existsSync( cloverPath ) ? parseClover( fs.readFileSync( cloverPath, 'utf8' ) ) : {},
			js: summaryPath && fs.existsSync( summaryPath ) ? parseJestSummary( readJson( summaryPath ) ) : {},
		} );

		coverage = {
			head: build( PHP_CLOVER, JS_SUMMARY ),
			base: build( PHP_BASE_CLOVER, JS_BASE_SUMMARY ),
			floors: thresholds.coverage,
		};
	}

	fs.writeFileSync(
		OUTPUT,
		renderPrReport( {
			metrics,
			coverage,
			headSha: HEAD_SHA,
			baseSha: BASE_SHA,
			runUrl: RUN_URL,
		} )
	);
}
