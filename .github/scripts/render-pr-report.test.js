'use strict';

const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const { renderMetricsSection, renderCoverageSection, renderPrReport } = require( './render-pr-report' );

const SCRIPT = path.resolve( __dirname, 'render-pr-report.js' );
const HEAD_SHA = 'aaaaaaa1111111111111111111111111111111111';
const BASE_SHA = 'bbbbbbb2222222222222222222222222222222222';
const RUN_URL = 'https://github.com/o/r/actions/runs/1';

// Mirrors quality-thresholds.json's metrics.max — kept local so these tests
// don't break if the real file's numbers change.
const MAX = {
	critical: 0,
	error: 0,
	avgCyclomaticComplexityByClass: 8,
	logicalLinesByMethod: 12,
	lackCohesionOfMethods: 3.5,
	avgEfferentCoupling: 4,
};

const FLOORS = {
	php: { lines: 90, methods: 90 },
	js: { statements: 95, branches: 90, functions: 95, lines: 95 },
};

/**
 * Builds renderMetricsSection input, defaulting both branches to a
 * comparable baseline.
 *
 * @param {Object} head Sections to merge into the PR summary.
 * @param {Object} base Sections to merge into the base summary.
 * @param {Object} max  Overrides for quality-thresholds.json's metrics.max.
 * @return {Object} Input for renderMetricsSection.
 */
const metricsInput = ( head = {}, base = {}, max = MAX ) => ( {
	head: { OOP: { classes: 5 }, ...head },
	base: { OOP: { classes: 5 }, ...base },
	max,
} );

/**
 * Extracts the table row for a label, so assertions do not depend on
 * where the metric happens to sit in the table.
 *
 * @param {string} body  Rendered markdown.
 * @param {string} label Row label (metric label, or "Suite | Metric").
 * @return {string|undefined} The row, if rendered.
 */
const row = ( body, label ) => body.split( '\n' ).find( ( line ) => line.startsWith( `| ${ label } |` ) );

/**
 * Builds a coverage Metric.
 *
 * @param {number} covered Covered units.
 * @param {number} total   Total units.
 * @param {number} pct     Percentage covered.
 * @return {Object} A Metric.
 */
const metric = ( covered, total, pct ) => ( { covered, total, pct } );

describe( 'renderMetricsSection', () => {
	it( 'renders a five-column diff table against a comparable base', () => {
		const body = renderMetricsSection( metricsInput() );

		expect( body ).toContain( '### Code Metrics' );
		expect( body ).toContain( '| Metric | Base | PR | Δ | Max |' );
	} );

	it( 'reports no regression and no breach when nothing moved', () => {
		const body = renderMetricsSection(
			metricsInput( { Complexity: { avgCyclomaticComplexityByClass: 6 } }, { Complexity: { avgCyclomaticComplexityByClass: 6 } } )
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction. All within threshold.' );
		expect( row( body, 'Avg cyclomatic complexity / class' ) ).toBe(
			'| Avg cyclomatic complexity / class | 6 | 6 | — | 8 ✅ |'
		);
	} );

	it( 'flags and counts a metric that rose, and shows it is still within its ceiling', () => {
		const body = renderMetricsSection(
			metricsInput(
				{ Complexity: { avgCyclomaticComplexityByClass: 6.49 } },
				{ Complexity: { avgCyclomaticComplexityByClass: 6.31 } }
			)
		);

		expect( body ).toContain( '⚠️ 1 metric moved in the wrong direction. All within threshold.' );
		expect( row( body, 'Avg cyclomatic complexity / class' ) ).toBe(
			'| Avg cyclomatic complexity / class | 6.31 | 6.49 | ⚠️ +0.18 | 8 ✅ |'
		);
	} );

	it( 'pluralises the regression count', () => {
		const body = renderMetricsSection(
			metricsInput(
				{ Complexity: { avgCyclomaticComplexityByClass: 6.5 }, Coupling: { avgEfferentCoupling: 3 } },
				{ Complexity: { avgCyclomaticComplexityByClass: 6 }, Coupling: { avgEfferentCoupling: 2 } }
			)
		);

		expect( body ).toContain( '⚠️ 2 metrics moved in the wrong direction. All within threshold.' );
	} );

	it( 'does not flag a metric that fell', () => {
		const body = renderMetricsSection(
			metricsInput(
				{ Complexity: { avgCyclomaticComplexityByClass: 6 } },
				{ Complexity: { avgCyclomaticComplexityByClass: 6.5 } }
			)
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction. All within threshold.' );
		expect( row( body, 'Avg cyclomatic complexity / class' ) ).toBe(
			'| Avg cyclomatic complexity / class | 6.50 | 6 | -0.50 | 8 ✅ |'
		);
	} );

	it( 'treats movement below two decimal places as no change', () => {
		const body = renderMetricsSection(
			metricsInput(
				{ Complexity: { avgCyclomaticComplexityByClass: 1.234 } },
				{ Complexity: { avgCyclomaticComplexityByClass: 1.23 } }
			)
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction. All within threshold.' );
		expect( row( body, 'Avg cyclomatic complexity / class' ) ).toBe(
			'| Avg cyclomatic complexity / class | 1.23 | 1.23 | — | 8 ✅ |'
		);
	} );

	it( 'omits a metric absent from both summaries', () => {
		const body = renderMetricsSection( metricsInput() );

		expect( row( body, 'Lack of cohesion of methods' ) ).toBeUndefined();
	} );

	it( 'renders n/a and no delta for a metric only the PR reports', () => {
		const body = renderMetricsSection( metricsInput( { OOP: { classes: 5, lackCohesionOfMethods: 2 } } ) );

		expect( row( body, 'Lack of cohesion of methods' ) ).toBe(
			'| Lack of cohesion of methods | n/a | 2 | — | 3.50 ✅ |'
		);
	} );

	it( 'ignores non-numeric values', () => {
		const body = renderMetricsSection( metricsInput( { OOP: { classes: 5, lackCohesionOfMethods: 'n/a' } } ) );

		expect( row( body, 'Lack of cohesion of methods' ) ).toBeUndefined();
	} );

	it( 'only reports the six metrics with a configured threshold', () => {
		const body = renderMetricsSection(
			metricsInput( { LOC: { linesOfCode: 900 }, Bugs: { avgBugsByClass: 0.5 } } )
		);

		expect( row( body, 'Lines of code' ) ).toBeUndefined();
		expect( row( body, 'Avg bugs / class' ) ).toBeUndefined();
	} );

	it( 'throws a clear error for an unknown key in metrics.max', () => {
		expect( () => renderMetricsSection( metricsInput( {}, {}, { notARealMetric: 5 } ) ) ).toThrow(
			/notARealMetric/
		);
	} );

	describe( 'threshold breaches', () => {
		it( 'flags a metric that breaches its ceiling with ❌', () => {
			const body = renderMetricsSection( metricsInput( { Violations: { critical: 1 } }, { Violations: { critical: 0 } } ) );

			expect( row( body, 'Violations: critical' ) ).toBe( '| Violations: critical | 0 | 1 | ⚠️ +1 | 0 ❌ |' );
		} );

		it( 'reports a breach even with no movement against the base', () => {
			const body = renderMetricsSection( metricsInput( { Violations: { critical: 1 } }, { Violations: { critical: 1 } } ) );

			expect( body ).toContain( '❌ No metric moved in the wrong direction. 1 metric breached threshold.' );
			expect( row( body, 'Violations: critical' ) ).toBe( '| Violations: critical | 1 | 1 | — | 0 ❌ |' );
		} );

		it( 'states both signals together when a metric regresses and breaches at once', () => {
			const body = renderMetricsSection( metricsInput( { Violations: { critical: 1 } }, { Violations: { critical: 0 } } ) );

			expect( body ).toContain( '❌ 1 metric moved in the wrong direction. 1 metric breached threshold.' );
		} );

		it( 'does not flag a metric within its ceiling even without a baseline value', () => {
			const body = renderMetricsSection( metricsInput( { OOP: { classes: 5, lackCohesionOfMethods: 3.5 } } ) );

			expect( row( body, 'Lack of cohesion of methods' ) ).toBe(
				'| Lack of cohesion of methods | n/a | 3.50 | — | 3.50 ✅ |'
			);
		} );
	} );

	describe( 'when the base declares no classes', () => {
		const noBaseline = ( head = { Complexity: { avgCyclomaticComplexityByClass: 6 } } ) =>
			renderMetricsSection( { ...metricsInput( head ), base: { OOP: { classes: 0 } } } );

		it( 'explains why there is no diff', () => {
			expect( noBaseline() ).toContain(
				'ℹ️ Base declares no classes, so there is no class-based baseline to diff against. Showing this branch on its own. All within threshold.'
			);
		} );

		it( 'falls back to a three-column table of the PR alone', () => {
			const body = noBaseline();

			expect( body ).toContain( '| Metric | PR | Max |' );
			expect( body ).not.toContain( '| Metric | Base | PR | Δ | Max |' );
			expect( row( body, 'Avg cyclomatic complexity / class' ) ).toBe(
				'| Avg cyclomatic complexity / class | 6 | 8 ✅ |'
			);
		} );

		it( 'still flags a threshold breach without a baseline to compare against', () => {
			const body = noBaseline( { Violations: { critical: 1 } } );

			expect( body ).toContain( '❌' );
			expect( body ).toContain( '1 metric breached threshold.' );
			expect( row( body, 'Violations: critical' ) ).toBe( '| Violations: critical | 1 | 0 ❌ |' );
		} );
	} );
} );

describe( 'renderCoverageSection', () => {
	describe( 'rejecting a floor it would never apply', () => {
		const head = { php: { lines: metric( 80, 100, 80 ), methods: null, branches: null }, js: {} };

		it( 'throws on a misspelled metric rather than reporting no floor', () => {
			// "line" would render as "no floor configured", which is
			// indistinguishable from deliberately not gating it — so 80%
			// against a 90% floor would show a bare "—" instead of ❌.
			expect( () => renderCoverageSection( {
				head,
				base: { php: {}, js: {} },
				floors: { php: { line: 90 } },
			} ) ).toThrow( /php\.line/ );
		} );

		it( 'throws on an unknown suite', () => {
			expect( () => renderCoverageSection( {
				head,
				base: { php: {}, js: {} },
				floors: { python: { lines: 90 } },
			} ) ).toThrow( /python/ );
		} );

		it( 'accepts the real quality-thresholds.json', () => {
			const { coverage } = require( '../../quality-thresholds.json' );

			expect( () => renderCoverageSection( { head, base: { php: {}, js: {} }, floors: coverage } ) ).not.toThrow();
		} );
	} );

	const phpHead = { lines: metric( 946, 1000, 94.61 ), methods: metric( 90, 100, 90 ), branches: null };
	const phpBase = { lines: metric( 946, 1000, 94.61 ), methods: metric( 90, 100, 90 ), branches: null };
	const jsHead = {
		statements: metric( 100, 100, 100 ),
		branches: metric( 90, 100, 90 ),
		functions: metric( 100, 100, 100 ),
		lines: metric( 100, 100, 100 ),
	};
	const jsBase = jsHead;

	it( 'renders a six-column diff table against a comparable base', () => {
		const body = renderCoverageSection( { head: { php: phpHead, js: jsHead }, base: { php: phpBase, js: jsBase }, floors: FLOORS } );

		expect( body ).toContain( '### Coverage' );
		expect( body ).toContain( '| Suite | Metric | Base | PR | Δ | Floor |' );
		expect( row( body, 'JS | Statements' ) ).toBe( '| JS | Statements | 100% | 100% | — | 95% ✅ |' );
	} );

	it( 'renders n/a, never 0%, for an unmeasured metric, and never marks it breached', () => {
		const body = renderCoverageSection( { head: { php: phpHead, js: jsHead }, base: { php: phpBase, js: jsBase }, floors: FLOORS } );

		expect( row( body, 'PHP | Branches' ) ).toBe( '| PHP | Branches | n/a | n/a | — | — |' );
	} );

	it( 'flags a suite metric that falls below its floor with ❌', () => {
		const head = { ...jsHead, statements: metric( 80, 100, 80 ) };
		const body = renderCoverageSection( { head: { php: phpHead, js: head }, base: { php: phpBase, js: jsBase }, floors: FLOORS } );

		expect( row( body, 'JS | Statements' ) ).toBe( '| JS | Statements | 100% | 80% | -20% | 95% ❌ |' );
	} );

	it( 'renders a fractional delta to two decimal places', () => {
		const head = { ...phpHead, lines: metric( 945, 1000, 94.54 ) };
		const body = renderCoverageSection( { head: { php: head, js: jsHead }, base: { php: phpBase, js: jsBase }, floors: FLOORS } );

		expect( row( body, 'PHP | Lines' ) ).toBe( '| PHP | Lines | 94.61% | 94.54% | -0.07% | 90% ✅ |' );
	} );

	describe( 'when the base has no coverage data', () => {
		const noBaselineFloors = FLOORS;
		const empty = { php: { lines: null, methods: null, branches: null }, js: { statements: null, branches: null, functions: null, lines: null } };

		it( 'explains why there is no diff', () => {
			const body = renderCoverageSection( { head: { php: phpHead, js: jsHead }, base: empty, floors: noBaselineFloors } );

			expect( body ).toContain( "ℹ️ No coverage data for the base branch. Showing the PR's numbers alone." );
		} );

		it( 'falls back to a four-column table of the PR alone', () => {
			const body = renderCoverageSection( { head: { php: phpHead, js: jsHead }, base: empty, floors: noBaselineFloors } );

			expect( body ).toContain( '| Suite | Metric | PR | Floor |' );
			expect( body ).not.toContain( '| Suite | Metric | Base | PR | Δ | Floor |' );
			expect( row( body, 'JS | Statements' ) ).toBe( '| JS | Statements | 100% | 95% ✅ |' );
		} );

		it( 'treats a missing base object the same as one with no measured metrics', () => {
			const body = renderCoverageSection( { head: { php: phpHead, js: jsHead }, base: null, floors: noBaselineFloors } );

			expect( body ).toContain( "ℹ️ No coverage data for the base branch." );
		} );
	} );
} );

describe( 'renderPrReport', () => {
	const metrics = metricsInput( { Complexity: { avgCyclomaticComplexityByClass: 6.49 } }, { Complexity: { avgCyclomaticComplexityByClass: 6.31 } } );
	const coverage = {
		head: { php: { lines: metric( 946, 1000, 94.61 ), methods: null, branches: null }, js: { statements: metric( 100, 100, 100 ), branches: null, functions: null, lines: null } },
		base: { php: { lines: metric( 946, 1000, 94.61 ), methods: null, branches: null }, js: { statements: metric( 100, 100, 100 ), branches: null, functions: null, lines: null } },
		floors: FLOORS,
	};

	it( 'assembles the heading, SHA line, headline, both sections and the footer in order', () => {
		const body = renderPrReport( { metrics, coverage, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

		expect( body ).toContain( '## 📊 PR Report' );
		expect( body ).toContain( '`bbbbbbb` (base) → `aaaaaaa` (PR)' );
		expect( body ).toContain( '⚠️ 1 metric moved in the wrong direction. All within threshold.' );
		expect( body.indexOf( '### Code Metrics' ) ).toBeLessThan( body.indexOf( '### Coverage' ) );
		expect( body ).toContain( `[the Integrate run](${ RUN_URL })` );
	} );

	it( 'truncates both SHAs to seven characters', () => {
		const body = renderPrReport( { metrics, coverage, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

		expect( body ).not.toContain( HEAD_SHA );
		expect( body ).not.toContain( BASE_SHA );
	} );

	it( 'omits the Code Metrics section when there is no metrics data', () => {
		const body = renderPrReport( { metrics: null, coverage, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

		expect( body ).not.toContain( '### Code Metrics' );
		expect( body ).toContain( '### Coverage' );
	} );

	it( 'omits the Coverage section when there is no coverage data', () => {
		const body = renderPrReport( { metrics, coverage: null, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

		expect( body ).toContain( '### Code Metrics' );
		expect( body ).not.toContain( '### Coverage' );
	} );

	describe( 'the delta and the cells beside it', () => {
		/**
		 * Reads the Base, PR and Δ cells of a metrics row.
		 *
		 * @param {number} before Base value.
		 * @param {number} after  PR value.
		 * @return {string[]} The three cells, trimmed.
		 */
		const cells = ( before, after ) => {
			const body = renderMetricsSection( metricsInput(
				{ Complexity: { avgCyclomaticComplexityByClass: after } },
				{ Complexity: { avgCyclomaticComplexityByClass: before } }
			) );
			return row( body, 'Avg cyclomatic complexity / class' )
				.split( '|' ).slice( 2, 5 ).map( ( cell ) => cell.trim() );
		};

		it( 'reports a change the cells show', () => {
			// 6.004 and 6.006 display as 6.00 and 6.01; their raw difference
			// rounds to zero, which would print "—" beside two visibly
			// different numbers.
			expect( cells( 6.004, 6.006 ) ).toEqual( [ '6.00', '6.01', '⚠️ +0.01' ] );
		} );

		it( 'reports no change when the cells are identical', () => {
			// Both display as 0.01, so a delta of +0.01 would contradict them.
			expect( cells( 0.005, 0.015 ) ).toEqual( [ '0.01', '0.01', '—' ] );
		} );
	} );

	describe( 'the SHA line', () => {
		const noClasses = metricsInput( { Complexity: { avgCyclomaticComplexityByClass: 6.49 } }, { OOP: { classes: 0 } } );

		it( 'drops the base arrow when no section found a baseline', () => {
			// Claiming "base → PR" while every section reports it had nothing
			// to diff against contradicts the sections' own ℹ️ notes.
			const body = renderPrReport( { metrics: noClasses, coverage: null, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

			expect( body ).toContain( '`aaaaaaa` (PR)' );
			expect( body ).not.toContain( '(base) →' );
		} );

		it( 'keeps the base arrow when only one section lacks a baseline', () => {
			const body = renderPrReport( { metrics: noClasses, coverage, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

			expect( body ).toContain( '`bbbbbbb` (base) → `aaaaaaa` (PR)' );
		} );

		it( 'drops the base arrow when coverage is the only section and has no baseline', () => {
			const noBaseCoverage = { ...coverage, base: { php: {}, js: {} } };
			const body = renderPrReport( { metrics: null, coverage: noBaseCoverage, headSha: HEAD_SHA, baseSha: BASE_SHA, runUrl: RUN_URL } );

			expect( body ).toContain( '`aaaaaaa` (PR)' );
			expect( body ).not.toContain( '(base) →' );
		} );
	} );
} );

describe( 'the CLI wrapper', () => {
	let dir;

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( os.tmpdir(), 'pr-report-' ) );
	} );

	afterEach( () => {
		fs.rmSync( dir, { recursive: true, force: true } );
	} );

	/**
	 * Runs the script the way integrate.yml does.
	 *
	 * @param {Object} env Environment overrides.
	 * @return {Object} The spawn result.
	 */
	const run = ( env = {} ) =>
		execFileSync( process.execPath, [ SCRIPT ], {
			cwd: dir,
			encoding: 'utf8',
			stdio: 'pipe',
			env: {
				...process.env,
				HEAD_SUMMARY: 'head.json',
				BASE_SUMMARY: 'base.json',
				THRESHOLDS: path.resolve( __dirname, '..', '..', 'quality-thresholds.json' ),
				OUTPUT: 'comment.md',
				HEAD_SHA,
				BASE_SHA,
				RUN_URL,
				...env,
			},
		} );

	it( 'reads both summaries and writes the rendered report, without touching coverage', () => {
		// No PHP_CLOVER/JS_SUMMARY set, so the CLI must not require('./coverage').
		fs.writeFileSync( path.join( dir, 'head.json' ), JSON.stringify( { OOP: { classes: 6 } } ) );
		fs.writeFileSync( path.join( dir, 'base.json' ), JSON.stringify( { OOP: { classes: 5 } } ) );

		run();

		const written = fs.readFileSync( path.join( dir, 'comment.md' ), 'utf8' );
		expect( written ).toContain( '## 📊 PR Report' );
		expect( written ).toContain( '### Code Metrics' );
		expect( written ).not.toContain( '### Coverage' );
	} );

	it( 'fails loudly when the workflow forgets to pass a SHA', () => {
		expect( () => run( { HEAD_SHA: '' } ) ).toThrow( /HEAD_SHA is not set/ );
		expect( fs.existsSync( path.join( dir, 'comment.md' ) ) ).toBe( false );
	} );

	describe( 'reading real coverage files', () => {
		// The env var names here are the ones the report job sets, against the
		// file names unit-tests.yml stages into the artifact. A rename on
		// either side fails silently in production — the section just stops
		// appearing — so it is pinned here rather than left to review.
		const clover = ( statements, covered ) =>
			`<?xml version="1.0" encoding="UTF-8"?><coverage><project timestamp="1">` +
			`<file name="a.php"><metrics statements="1" coveredstatements="1"/></file>` +
			`<metrics files="1" methods="10" coveredmethods="9" conditionals="0" coveredconditionals="0" ` +
			`statements="${ statements }" coveredstatements="${ covered }"/></project></coverage>`;

		const jestSummary = ( covered ) => JSON.stringify( {
			total: {
				statements: { total: 100, covered, skipped: 0, pct: covered },
				branches: { total: 100, covered, skipped: 0, pct: covered },
				functions: { total: 100, covered, skipped: 0, pct: covered },
				lines: { total: 100, covered, skipped: 0, pct: covered },
			},
		} );

		beforeEach( () => {
			fs.writeFileSync( path.join( dir, 'head.json' ), JSON.stringify( { OOP: { classes: 6 } } ) );
			fs.writeFileSync( path.join( dir, 'base.json' ), JSON.stringify( { OOP: { classes: 5 } } ) );
			fs.writeFileSync( path.join( dir, 'php-clover.xml' ), clover( 1000, 940 ) );
			fs.writeFileSync( path.join( dir, 'php-base-clover.xml' ), clover( 1000, 950 ) );
			fs.writeFileSync( path.join( dir, 'js-summary.json' ), jestSummary( 99 ) );
			fs.writeFileSync( path.join( dir, 'js-base-summary.json' ), jestSummary( 99 ) );
		} );

		const withCoverage = ( env = {} ) => run( {
			PHP_CLOVER: 'php-clover.xml',
			PHP_BASE_CLOVER: 'php-base-clover.xml',
			JS_SUMMARY: 'js-summary.json',
			JS_BASE_SUMMARY: 'js-base-summary.json',
			...env,
		} );

		it( 'renders the coverage section from clover and the jest summary', () => {
			withCoverage();

			const written = fs.readFileSync( path.join( dir, 'comment.md' ), 'utf8' );
			expect( written ).toContain( '### Coverage' );
			expect( written ).toContain( '| PHP | Lines | 95% | 94% | -1% | 90% ✅ |' );
			// Xdebug leaves conditionals at 0/0, which is unmeasured, not zero.
			expect( written ).toContain( '| PHP | Branches | n/a | n/a | — | — |' );
			expect( written ).toContain( '| JS | Statements | 99% | 99% | — | 95% ✅ |' );
		} );

		it( 'degrades to the PR column when the base coverage files are absent', () => {
			fs.rmSync( path.join( dir, 'php-base-clover.xml' ) );
			fs.rmSync( path.join( dir, 'js-base-summary.json' ) );

			withCoverage();

			const written = fs.readFileSync( path.join( dir, 'comment.md' ), 'utf8' );
			expect( written ).toContain( "ℹ️ No coverage data for the base branch." );
			expect( written ).toContain( '| Suite | Metric | PR | Floor |' );
		} );
	} );
} );
