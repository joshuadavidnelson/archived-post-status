'use strict';

const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );

const { parseClover, parseJestSummary, checkFloors } = require( './coverage' );
const { main } = require( './check-coverage' );

const SCRIPT = path.resolve( __dirname, 'check-coverage.js' );

// Mirrors the real project metrics element in quality-thresholds.json's
// floors: PHP lines/methods 94%+ (floor 90), JS all four metrics 92-100%
// (floors 95/90/95/95), so "passes" and "fails" fixtures below only need to
// nudge one number to cross a floor.
const CLOVER_PASS = `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1785868683">
  <project timestamp="1785868683">
    <metrics files="52" loc="6078" ncloc="2917" classes="48" methods="186" coveredmethods="176" conditionals="0" coveredconditionals="0" statements="1228" coveredstatements="1161" elements="1414" coveredelements="1337"/>
  </project>
</coverage>`;

const THRESHOLDS_JSON = JSON.stringify( {
	coverage: {
		php: { lines: 90, methods: 90 },
		js: { statements: 95, branches: 90, functions: 95, lines: 95 },
	},
} );

/**
 * Builds a minimal fs stand-in over an in-memory file map, for driving
 * main() without touching the real filesystem.
 *
 * @param {Object<string, string>} files File contents keyed by path.
 * @return {{existsSync: Function, readFileSync: Function}} A fake fs.
 */
function fakeFs( files ) {
	return {
		existsSync: ( filePath ) => Object.prototype.hasOwnProperty.call( files, filePath ),
		readFileSync: ( filePath ) => {
			if ( ! Object.prototype.hasOwnProperty.call( files, filePath ) ) {
				throw new Error( `ENOENT: no such file, open '${ filePath }'` );
			}
			return files[ filePath ];
		},
	};
}

/**
 * Builds a jest coverage-summary "total" object where every metric sits at
 * the given pct (as a clean fraction), so tests can nudge a single metric.
 *
 * @param {Object} overrides Per-metric { covered, total } overrides.
 * @return {string} JSON text for coverage-summary.json.
 */
function jsSummary( overrides = {} ) {
	const base = { statements: { total: 100, covered: 96 }, branches: { total: 50, covered: 46 }, functions: { total: 20, covered: 19 }, lines: { total: 100, covered: 96 } };
	const merged = { ...base, ...overrides };
	const total = {};
	for ( const [ key, { covered, total: t } ] of Object.entries( merged ) ) {
		total[ key ] = { total: t, covered, skipped: 0, pct: Math.round( ( covered / t ) * 10000 ) / 100 };
	}
	return JSON.stringify( { total } );
}

describe( 'parseClover', () => {
	it( 'parses project-level lines, methods and branches', () => {
		const xml = `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1700000000">
  <project timestamp="1700000000">
    <metrics files="2" loc="100" ncloc="80" classes="2" methods="10" coveredmethods="9" conditionals="4" coveredconditionals="2" statements="50" coveredstatements="45" elements="64" coveredelements="56"/>
  </project>
</coverage>`;

		const result = parseClover( xml );

		expect( result.lines ).toEqual( { covered: 45, total: 50, pct: 90 } );
		expect( result.methods ).toEqual( { covered: 9, total: 10, pct: 90 } );
		expect( result.branches ).toEqual( { covered: 2, total: 4, pct: 50 } );
	} );

	it( 'parses the real 52-file project metrics element', () => {
		const xml = `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1785868683">
  <project timestamp="1785868683">
    <metrics files="52" loc="6078" ncloc="2917" classes="48" methods="186" coveredmethods="176" conditionals="0" coveredconditionals="0" statements="1228" coveredstatements="1161" elements="1414" coveredelements="1337"/>
  </project>
</coverage>`;

		const result = parseClover( xml );

		expect( result.lines ).toEqual( { covered: 1161, total: 1228, pct: 94.54 } );
		expect( result.methods ).toEqual( { covered: 176, total: 186, pct: 94.62 } );
		// conditionals="0" is the normal case for Xdebug line coverage, not an error.
		expect( result.branches ).toBeNull();
	} );

	it( 'does not mistake a file-level <metrics> that precedes the project total', () => {
		const xml = `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="1700000000">
  <project timestamp="1700000000">
    <file name="a.php">
      <class name="A">
        <metrics complexity="3" methods="2" coveredmethods="2" conditionals="0" coveredconditionals="0" statements="10" coveredstatements="10" elements="12" coveredelements="12"/>
      </class>
      <metrics loc="20" ncloc="15" classes="1" methods="2" coveredmethods="2" conditionals="0" coveredconditionals="0" statements="10" coveredstatements="1" elements="12" coveredelements="3"/>
    </file>
    <metrics files="1" loc="20" ncloc="15" classes="1" methods="2" coveredmethods="2" conditionals="0" coveredconditionals="0" statements="10" coveredstatements="10" elements="12" coveredelements="12"/>
  </project>
</coverage>`;

		// The file-level <metrics> above reports 1/10 statements covered
		// (10%). If it were picked up instead of the project total (which
		// carries "files" and reports 10/10), this assertion would see 10.
		expect( parseClover( xml ).lines ).toEqual( { covered: 10, total: 10, pct: 100 } );
	} );

	it( 'throws when there is no <project> element', () => {
		expect( () => parseClover( '<coverage></coverage>' ) ).toThrow( /no <project>/ );
	} );

	it( 'throws when the project has no metrics element', () => {
		expect( () =>
			parseClover( '<coverage><project timestamp="1"></project></coverage>' )
		).toThrow( /no project-level <metrics>/ );
	} );
} );

describe( 'parseJestSummary', () => {
	it( 'parses statements, branches, functions and lines from total', () => {
		const summary = {
			total: {
				lines: { total: 41, covered: 41, skipped: 0, pct: 100 },
				statements: { total: 41, covered: 41, skipped: 0, pct: 100 },
				functions: { total: 13, covered: 13, skipped: 0, pct: 100 },
				branches: { total: 35, covered: 33, skipped: 0, pct: 94.28 },
				branchesTrue: { total: 0, covered: 0, skipped: 0, pct: 'Unknown' },
			},
		};

		const result = parseJestSummary( summary );

		expect( result.statements ).toEqual( { covered: 41, total: 41, pct: 100 } );
		expect( result.lines ).toEqual( { covered: 41, total: 41, pct: 100 } );
		expect( result.functions ).toEqual( { covered: 13, total: 13, pct: 100 } );
		// Recomputed by rounding rather than copied from Jest's own
		// floor-truncated 94.28 — see the dedicated rounding test below.
		expect( result.branches ).toEqual( { covered: 33, total: 35, pct: 94.29 } );
	} );

	it( 'ignores branchesTrue entirely', () => {
		const summary = {
			total: {
				lines: { total: 1, covered: 1, skipped: 0, pct: 100 },
				branchesTrue: { total: 99, covered: 0, skipped: 0, pct: 0 },
			},
		};

		expect( parseJestSummary( summary ) ).not.toHaveProperty( 'branchesTrue' );
	} );

	it( 'returns null for a 0-total metric instead of trusting the "Unknown" pct', () => {
		const summary = {
			total: {
				lines: { total: 1, covered: 1, skipped: 0, pct: 100 },
				branches: { total: 0, covered: 0, skipped: 0, pct: 'Unknown' },
			},
		};

		const result = parseJestSummary( summary );

		expect( result.branches ).toBeNull();
		expect( JSON.stringify( result ) ).not.toContain( 'Unknown' );
	} );

	it( 'recomputes pct by rounding rather than the floor truncation istanbul uses', () => {
		const summary = { total: { statements: { total: 3, covered: 2, skipped: 0, pct: 66.66 } } };

		expect( parseJestSummary( summary ).statements ).toEqual( { covered: 2, total: 3, pct: 66.67 } );
	} );
} );

describe( 'checkFloors', () => {
	const actual = {
		lines: { covered: 90, total: 100, pct: 90 },
		methods: { covered: 95, total: 100, pct: 95 },
		branches: null,
	};

	it( 'passes a metric above its floor', () => {
		expect( checkFloors( actual, { methods: 90 } ) ).toEqual( [
			{ metric: 'methods', pct: 95, floor: 90, ok: true },
		] );
	} );

	it( 'fails a metric below its floor', () => {
		expect( checkFloors( actual, { lines: 95 } ) ).toEqual( [
			{ metric: 'lines', pct: 90, floor: 95, ok: false },
		] );
	} );

	it( 'passes when a metric exactly equals its floor', () => {
		expect( checkFloors( actual, { lines: 90 } ) ).toEqual( [
			{ metric: 'lines', pct: 90, floor: 90, ok: true },
		] );
	} );

	it( 'treats an unmeasured (null) metric as a pass, not a failure', () => {
		expect( checkFloors( actual, { branches: 90 } ) ).toEqual( [
			{ metric: 'branches', pct: null, floor: 90, ok: true },
		] );
	} );

	it( 'returns one entry per floor key, in the order the keys appear', () => {
		expect( checkFloors( actual, { methods: 90, lines: 90 } ).map( ( r ) => r.metric ) ).toEqual( [
			'methods',
			'lines',
		] );
	} );

	it( 'throws for a floor key with no matching metric, rather than silently passing', () => {
		// A typo in quality-thresholds.json must be loud, not a free pass.
		expect( () => checkFloors( actual, { statements: 90 } ) ).toThrow( /statements/ );
	} );
} );

describe( 'main', () => {
	beforeEach( () => {
		jest.spyOn( console, 'log' ).mockImplementation( () => {} );
		jest.spyOn( console, 'error' ).mockImplementation( () => {} );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	const env = { PHP_CLOVER: 'clover.xml', JS_SUMMARY: 'summary.json', THRESHOLDS: 'thresholds.json' };

	it( 'passes when both suites are above their floors', () => {
		const fsStub = fakeFs( {
			'thresholds.json': THRESHOLDS_JSON,
			'clover.xml': CLOVER_PASS,
			'summary.json': jsSummary(),
		} );

		expect( main( { env, fs: fsStub } ) ).toBe( true );
	} );

	it( 'fails and emits ::error:: when a metric drops below its floor', () => {
		const fsStub = fakeFs( {
			'thresholds.json': THRESHOLDS_JSON,
			'clover.xml': CLOVER_PASS,
			'summary.json': jsSummary( { statements: { total: 100, covered: 90 } } ),
		} );

		expect( main( { env, fs: fsStub } ) ).toBe( false );
		expect( console.error ).toHaveBeenCalledWith(
			expect.stringContaining( '::error::JS statements is 90% (floor 95%)' )
		);
	} );

	it( 'passes when a metric sits exactly on its floor', () => {
		// functions is 19/20 = 95%, exactly the js.functions floor of 95.
		const fsStub = fakeFs( {
			'thresholds.json': THRESHOLDS_JSON,
			'clover.xml': CLOVER_PASS,
			'summary.json': jsSummary(),
		} );

		expect( main( { env, fs: fsStub } ) ).toBe( true );
		expect( console.log ).toHaveBeenCalledWith( 'JS functions: 95.00% (floor 95%) OK' );
	} );

	it( 'fails with a clear message when a coverage file is missing, rather than skipping it', () => {
		const fsStub = fakeFs( {
			'thresholds.json': THRESHOLDS_JSON,
			'summary.json': jsSummary(),
			// clover.xml intentionally absent.
		} );

		expect( main( { env, fs: fsStub } ) ).toBe( false );
		expect( console.error ).toHaveBeenCalledWith( '::error::PHP coverage file not found: clover.xml' );
	} );
} );

describe( 'the CLI wrapper', () => {
	let dir;

	beforeEach( () => {
		dir = fs.mkdtempSync( path.join( os.tmpdir(), 'check-coverage-' ) );
	} );

	afterEach( () => {
		fs.rmSync( dir, { recursive: true, force: true } );
	} );

	/**
	 * Runs the script the way the CI step does.
	 *
	 * @param {Object} env Environment overrides.
	 * @return {string} Captured stdout.
	 */
	const run = ( env = {} ) =>
		execFileSync( process.execPath, [ SCRIPT ], {
			cwd: dir,
			encoding: 'utf8',
			stdio: 'pipe',
			env: {
				...process.env,
				PHP_CLOVER: 'clover.xml',
				JS_SUMMARY: 'summary.json',
				THRESHOLDS: 'thresholds.json',
				...env,
			},
		} );

	it( 'exits 0 and prints a readable line per metric when everything is above floor', () => {
		fs.writeFileSync( path.join( dir, 'clover.xml' ), CLOVER_PASS );
		fs.writeFileSync( path.join( dir, 'summary.json' ), jsSummary() );
		fs.writeFileSync( path.join( dir, 'thresholds.json' ), THRESHOLDS_JSON );

		const stdout = run();

		expect( stdout ).toContain( 'PHP lines: 94.54% (floor 90%) OK' );
		expect( stdout ).toContain( 'JS statements: 96.00% (floor 95%) OK' );
	} );

	it( 'exits non-zero and emits ::error:: when a metric falls below its floor', () => {
		fs.writeFileSync( path.join( dir, 'clover.xml' ), CLOVER_PASS );
		fs.writeFileSync( path.join( dir, 'summary.json' ), jsSummary( { statements: { total: 100, covered: 90 } } ) );
		fs.writeFileSync( path.join( dir, 'thresholds.json' ), THRESHOLDS_JSON );

		let error;
		try {
			run();
		} catch ( caught ) {
			error = caught;
		}

		expect( error ).toBeDefined();
		expect( error.status ).toBe( 1 );
		expect( error.stderr ).toContain( '::error::JS statements is 90% (floor 95%)' );
	} );

	it( 'exits non-zero when a coverage file is missing', () => {
		fs.writeFileSync( path.join( dir, 'summary.json' ), jsSummary() );
		fs.writeFileSync( path.join( dir, 'thresholds.json' ), THRESHOLDS_JSON );
		// clover.xml intentionally not written.

		let error;
		try {
			run();
		} catch ( caught ) {
			error = caught;
		}

		expect( error ).toBeDefined();
		expect( error.status ).toBe( 1 );
		expect( error.stderr ).toContain( '::error::PHP coverage file not found: clover.xml' );
	} );
} );
