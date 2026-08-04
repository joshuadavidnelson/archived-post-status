'use strict';

const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const { renderMetricsComment } = require( './render-metrics-comment' );

const SCRIPT = path.resolve( __dirname, 'render-metrics-comment.js' );
const HEAD_SHA = 'aaaaaaa1111111111111111111111111111111111';
const BASE_SHA = 'bbbbbbb2222222222222222222222222222222222';
const RUN_URL = 'https://github.com/o/r/actions/runs/1';

/**
 * Builds render input, defaulting both branches to a comparable baseline.
 *
 * @param {Object} head Sections to merge into the PR summary.
 * @param {Object} base Sections to merge into the base summary.
 * @return {Object} Input for renderMetricsComment.
 */
const input = ( head = {}, base = {} ) => ( {
	head: { OOP: { classes: 5 }, ...head },
	base: { OOP: { classes: 5 }, ...base },
	headSha: HEAD_SHA,
	baseSha: BASE_SHA,
	runUrl: RUN_URL,
} );

/**
 * Extracts the table row for a label, so assertions do not depend on
 * where the metric happens to sit in the table.
 *
 * @param {string} body  Rendered comment.
 * @param {string} label Metric label.
 * @return {string|undefined} The row, if rendered.
 */
const row = ( body, label ) =>
	body.split( '\n' ).find( ( line ) => line.startsWith( `| ${ label } |` ) );

describe( 'renderMetricsComment', () => {
	it( 'renders a four-column diff table against a comparable base', () => {
		const body = renderMetricsComment( input() );

		expect( body ).toContain( '## 📊 Code Metrics Report' );
		expect( body ).toContain( '| Metric | Base | PR | Δ |' );
		expect( body ).toContain( '`bbbbbbb` (base) → `aaaaaaa` (PR)' );
		expect( body ).toContain( `[this run](${ RUN_URL })` );
	} );

	it( 'truncates both SHAs to seven characters', () => {
		const body = renderMetricsComment( input() );

		expect( body ).not.toContain( HEAD_SHA );
		expect( body ).not.toContain( BASE_SHA );
	} );

	it( 'reports no regression when nothing moved', () => {
		const body = renderMetricsComment(
			input( { Complexity: { avgDifficulty: 2 } }, { Complexity: { avgDifficulty: 2 } } )
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction.' );
		expect( row( body, 'Avg difficulty' ) ).toBe( '| Avg difficulty | 2 | 2 | — |' );
	} );

	it( 'flags and counts a lower-is-better metric that rose', () => {
		const body = renderMetricsComment(
			input( { Complexity: { avgDifficulty: 3.5 } }, { Complexity: { avgDifficulty: 2 } } )
		);

		expect( body ).toContain( '⚠️ 1 metric moved in the wrong direction.' );
		expect( row( body, 'Avg difficulty' ) ).toBe( '| Avg difficulty | 2 | 3.50 | ⚠️ +1.50 |' );
	} );

	it( 'pluralises the regression count', () => {
		const body = renderMetricsComment(
			input(
				{ Complexity: { avgDifficulty: 3 }, Violations: { critical: 4 } },
				{ Complexity: { avgDifficulty: 2 }, Violations: { critical: 1 } }
			)
		);

		expect( body ).toContain( '⚠️ 2 metrics moved in the wrong direction.' );
	} );

	it( 'does not flag a lower-is-better metric that fell', () => {
		const body = renderMetricsComment(
			input( { Complexity: { avgDifficulty: 2 } }, { Complexity: { avgDifficulty: 3.5 } } )
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction.' );
		expect( row( body, 'Avg difficulty' ) ).toBe( '| Avg difficulty | 3.50 | 2 | -1.50 |' );
	} );

	it( 'does not flag a neutral count that grew', () => {
		// Lines of code is reported, not judged — a bigger diff is not a
		// regression the way rising complexity is.
		const body = renderMetricsComment(
			input( { LOC: { linesOfCode: 900 } }, { LOC: { linesOfCode: 500 } } )
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction.' );
		expect( row( body, 'Lines of code' ) ).toBe( '| Lines of code | 500 | 900 | +400 |' );
	} );

	it( 'treats movement below two decimal places as no change', () => {
		const body = renderMetricsComment(
			input( { Complexity: { avgDifficulty: 1.234 } }, { Complexity: { avgDifficulty: 1.23 } } )
		);

		expect( body ).toContain( '✅ No metric moved in the wrong direction.' );
		expect( row( body, 'Avg difficulty' ) ).toBe( '| Avg difficulty | 1.23 | 1.23 | — |' );
	} );

	it( 'omits a metric absent from both summaries', () => {
		const body = renderMetricsComment( input() );

		expect( row( body, 'Avg difficulty' ) ).toBeUndefined();
	} );

	it( 'renders n/a and no delta for a metric only the PR reports', () => {
		const body = renderMetricsComment( input( { Bugs: { avgBugsByClass: 0.5 } } ) );

		expect( row( body, 'Avg bugs / class' ) ).toBe( '| Avg bugs / class | n/a | 0.50 | — |' );
	} );

	it( 'ignores non-numeric values', () => {
		const body = renderMetricsComment( input( { Bugs: { avgBugsByClass: 'n/a' } } ) );

		expect( row( body, 'Avg bugs / class' ) ).toBeUndefined();
	} );

	describe( 'when the base declares no classes', () => {
		const noBaseline = () =>
			renderMetricsComment( {
				...input( { LOC: { linesOfCode: 900 } } ),
				base: { OOP: { classes: 0 } },
			} );

		it( 'explains why there is no diff', () => {
			expect( noBaseline() ).toContain(
				'ℹ️ `bbbbbbb` declares no classes, so there is no class-based baseline to diff against.'
			);
		} );

		it( 'falls back to a two-column table of the PR alone', () => {
			const body = noBaseline();

			expect( body ).toContain( '| Metric | PR |' );
			expect( body ).not.toContain( '| Metric | Base | PR | Δ |' );
			expect( body ).toContain( '`aaaaaaa` (PR)' );
			expect( body ).not.toContain( '(base) →' );
			expect( row( body, 'Lines of code' ) ).toBe( '| Lines of code | 900 |' );
		} );
	} );
} );

describe( 'the CLI wrapper', () => {
	let dir;

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( os.tmpdir(), 'metrics-comment-' ) );
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
				OUTPUT: 'comment.md',
				HEAD_SHA,
				BASE_SHA,
				RUN_URL,
				...env,
			},
		} );

	it( 'reads both summaries and writes the rendered comment', () => {
		fs.writeFileSync(
			path.join( dir, 'head.json' ),
			JSON.stringify( { OOP: { classes: 6 } } )
		);
		fs.writeFileSync(
			path.join( dir, 'base.json' ),
			JSON.stringify( { OOP: { classes: 5 } } )
		);

		run();

		expect( fs.readFileSync( path.join( dir, 'comment.md' ), 'utf8' ) ).toBe(
			renderMetricsComment( {
				head: { OOP: { classes: 6 } },
				base: { OOP: { classes: 5 } },
				headSha: HEAD_SHA,
				baseSha: BASE_SHA,
				runUrl: RUN_URL,
			} )
		);
	} );

	it( 'fails loudly when the workflow forgets to pass a SHA', () => {
		expect( () => run( { HEAD_SHA: '' } ) ).toThrow( /HEAD_SHA is not set/ );
		expect( fs.existsSync( path.join( dir, 'comment.md' ) ) ).toBe( false );
	} );
} );
