/**
 * Renders the PHPMetrics comparison posted as a sticky PR comment.
 *
 * Called by the code-analysis job in .github/workflows/integrate.yml, which
 * generates both summaries first. Kept out of the workflow so it can be unit
 * tested — see render-metrics-comment.test.js.
 */

'use strict';

// [ section, key, label, lowerIsBetter ]
const METRICS = [
	[ 'LOC', 'linesOfCode', 'Lines of code', false ],
	[ 'LOC', 'logicalLinesOfCode', 'Logical lines of code', false ],
	[ 'LOC', 'logicalLinesByMethod', 'Logical lines per method', true ],
	[ 'OOP', 'classes', 'Classes', false ],
	[ 'OOP', 'methods', 'Methods', false ],
	[ 'OOP', 'lackCohesionOfMethods', 'Lack of cohesion of methods', true ],
	[ 'Complexity', 'avgCyclomaticComplexityByClass', 'Avg cyclomatic complexity / class', true ],
	[ 'Complexity', 'avgWeightedMethodCountByClass', 'Avg weighted method count / class', true ],
	[ 'Complexity', 'avgRelativeSystemComplexity', 'Avg relative system complexity', true ],
	[ 'Complexity', 'avgDifficulty', 'Avg difficulty', true ],
	[ 'Coupling', 'avgEfferentCoupling', 'Avg efferent coupling', true ],
	[ 'Coupling', 'avgInstability', 'Avg instability', true ],
	[ 'Bugs', 'avgBugsByClass', 'Avg bugs / class', true ],
	[ 'Bugs', 'avgDefectsByClass', 'Avg defects / class (Kan)', true ],
	[ 'Violations', 'critical', 'Violations: critical', true ],
	[ 'Violations', 'error', 'Violations: error', true ],
	[ 'Violations', 'warning', 'Violations: warning', true ],
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
	const found = ( report[ section ] || {} )[ key ];
	return typeof found === 'number' ? found : null;
}

/**
 * Formats a metric for the table: integers bare, everything else to 2dp.
 *
 * @param {number|null} n The value.
 * @return {string} The rendered cell.
 */
function format( n ) {
	if ( n === null ) {
		return 'n/a';
	}
	return Number.isInteger( n ) ? String( n ) : n.toFixed( 2 );
}

/**
 * Builds the comment body.
 *
 * @param {Object} input         Render input.
 * @param {Object} input.head    PHPMetrics summary for the PR branch.
 * @param {Object} input.base    PHPMetrics summary for the base branch.
 * @param {string} input.headSha Full SHA of the PR head.
 * @param {string} input.baseSha Full SHA of the base.
 * @param {string} input.runUrl  Link to the workflow run holding the artifact.
 * @return {string} Markdown body.
 */
function renderMetricsComment( { head, base, headSha, baseSha, runUrl } ) {
	// Every metric PHPMetrics summarises is derived from classes, so a base
	// with none reports 0 across the board — even linesOfCode. Diffing
	// against that would render the whole table as a regression from zero,
	// so fall back to reporting this branch on its own.
	const comparable = value( base, 'OOP', 'classes' ) > 0;

	const rows = [];
	let regressions = 0;

	for ( const [ section, key, label, lowerIsBetter ] of METRICS ) {
		const before = value( base, section, key );
		const after = value( head, section, key );
		if ( before === null && after === null ) {
			continue;
		}

		if ( ! comparable ) {
			rows.push( `| ${ label } | ${ format( after ) } |` );
			continue;
		}

		let delta = '—';
		if ( before !== null && after !== null ) {
			const change = Math.round( ( after - before ) * 100 ) / 100;
			if ( change !== 0 ) {
				const worse = lowerIsBetter && change > 0;
				if ( worse ) {
					regressions++;
				}
				delta = `${ worse ? '⚠️ ' : '' }${ change > 0 ? '+' : '-' }${ format( Math.abs( change ) ) }`;
			}
		}
		rows.push( `| ${ label } | ${ format( before ) } | ${ format( after ) } | ${ delta } |` );
	}

	const shortHead = headSha.slice( 0, 7 );
	const shortBase = baseSha.slice( 0, 7 );

	let headline;
	if ( ! comparable ) {
		headline = `ℹ️ \`${ shortBase }\` declares no classes, so there is no class-based baseline to diff against. Showing this branch on its own.`;
	} else if ( regressions === 0 ) {
		headline = '✅ No metric moved in the wrong direction.';
	} else {
		headline = `⚠️ ${ regressions } metric${ regressions === 1 ? '' : 's' } moved in the wrong direction.`;
	}

	return [
		'## 📊 Code Metrics Report',
		'',
		headline,
		'',
		comparable
			? `\`${ shortBase }\` (base) → \`${ shortHead }\` (PR)`
			: `\`${ shortHead }\` (PR)`,
		'',
		...( comparable
			? [ '| Metric | Base | PR | Δ |', '| --- | ---: | ---: | ---: |' ]
			: [ '| Metric | PR |', '| --- | ---: |' ] ),
		...rows,
		'',
		`<sub>PHPMetrics summary — the full JSON is attached to [this run](${ runUrl }) as \`phpmetrics-summary\`.</sub>`,
	].join( '\n' );
}

module.exports = { renderMetricsComment, METRICS };

/* istanbul ignore next -- CLI wiring, exercised by the integration test. */
if ( require.main === module ) {
	const fs = require( 'fs' );

	const {
		HEAD_SUMMARY = 'reports/metrics-summary.json',
		BASE_SUMMARY = 'reports/base-summary.json',
		OUTPUT = 'reports/metrics-comment.md',
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

	const read = ( path ) => JSON.parse( fs.readFileSync( path, 'utf8' ) );

	fs.writeFileSync(
		OUTPUT,
		renderMetricsComment( {
			head: read( HEAD_SUMMARY ),
			base: read( BASE_SUMMARY ),
			headSha: HEAD_SHA,
			baseSha: BASE_SHA,
			runUrl: RUN_URL,
		} )
	);
}
