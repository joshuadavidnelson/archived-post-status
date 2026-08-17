/**
 * Behavior tests for the shipped schedule-panel.js — the module under test
 * is imported, never re-implemented, so a regression in the real source
 * fails here. (The rendered panel is additionally covered end-to-end by the
 * Playwright editor specs.)
 */
const {
	apsSchedulePanel,
	apsRegisterSchedulePanel,
	LOCAL_INPUT_KEY,
	CLEAR_FLAG_KEY,
	DAYS_KEY,
} = require( './schedule-panel' );

/**
 * Build a fresh set of host globals. createElement records the element
 * tree as plain objects so structure and props are directly assertable,
 * exactly like block-editor.test.js's own doubles.
 */
function buildGlobals( scheduleOverrides = {} ) {
	const editPost = jest.fn();

	return {
		wp: {
			element: {
				createElement: jest.fn( ( type, props, ...children ) => ( {
					type,
					props,
					children,
				} ) ),
				useState: jest.fn( ( initial ) => [ initial, jest.fn() ] ),
			},
			editPost: {
				PluginDocumentSettingPanel: 'PluginDocumentSettingPanel',
			},
			data: {
				dispatch: jest.fn( () => ( { editPost } ) ),
			},
			plugins: { registerPlugin: jest.fn() },
			i18n: { __: jest.fn( ( text ) => text ) },
		},
		archivedPostStatusSchedule: {
			canSchedule: true,
			outcome: 'Not scheduled to archive.',
			exactDate: '',
			days: '',
			daysStatusText: '',
			...scheduleOverrides,
		},
		_editPostSpy: editPost,
	};
}

/** Find a rendered element by its `id` prop, depth-first. */
function findById( tree, id ) {
	if ( ! tree || typeof tree !== 'object' ) {
		return null;
	}
	if ( tree.props && tree.props.id === id ) {
		return tree;
	}
	for ( const child of tree.children || [] ) {
		const found = findById( child, id );
		if ( found ) {
			return found;
		}
	}
	return null;
}

describe( 'schedule-panel: visibility', () => {
	test( 'renders nothing when the user cannot schedule this post', () => {
		const globals = buildGlobals( { canSchedule: false } );

		expect( apsSchedulePanel( globals ) ).toBeNull();
		expect( globals.wp.element.createElement ).not.toHaveBeenCalled();
	} );

	test( 'renders the panel inside PluginDocumentSettingPanel with the server-rendered outcome text', () => {
		const globals = buildGlobals( { outcome: 'Auto archive: 3 March 2027 — from Category: News (3 days).' } );

		const tree = apsSchedulePanel( globals );

		expect( tree.type ).toBe( 'PluginDocumentSettingPanel' );
		expect( tree.props.name ).toBe( 'archived-post-status-schedule' );

		const outcome = findById( tree, 'aps-schedule-outcome' );
		expect( outcome ).not.toBeNull();
		expect( outcome.children[ 0 ] ).toBe(
			'Auto archive: 3 March 2027 — from Category: News (3 days).'
		);
	} );

	test( 'labels are translated with the plugin text domain', () => {
		const globals = buildGlobals();

		apsSchedulePanel( globals );

		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			'Archive Schedule',
			'archived-post-status'
		);
		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			'Exact date',
			'archived-post-status'
		);
		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			'Auto archive after (days)',
			'archived-post-status'
		);
		expect( globals.wp.i18n.__ ).toHaveBeenCalledWith(
			'Clear schedule',
			'archived-post-status'
		);
	} );
} );

describe( 'schedule-panel: fields reflect server-localized state, unformatted', () => {
	test( 'the date field is a real, labeled datetime-local control, associated with the outcome text', () => {
		const globals = buildGlobals( { exactDate: '2027-03-03T10:00' } );

		const tree = apsSchedulePanel( globals );
		const dateInput = findById( tree, 'aps-schedule-date' );

		expect( dateInput.type ).toBe( 'input' );
		expect( dateInput.props.type ).toBe( 'datetime-local' );
		expect( dateInput.props.value ).toBe( '2027-03-03T10:00' );
		expect( dateInput.props[ 'aria-describedby' ] ).toBe(
			'aps-schedule-outcome'
		);
	} );

	test( 'the days field reflects the post\'s own stored override verbatim', () => {
		const globals = buildGlobals( { days: '6' } );

		const tree = apsSchedulePanel( globals );
		const daysInput = findById( tree, 'aps-schedule-days' );

		expect( daysInput.props.type ).toBe( 'number' );
		expect( daysInput.props.value ).toBe( '6' );
	} );

	test( 'Clear is a real <button>, not a styled link', () => {
		const globals = buildGlobals();

		const tree = apsSchedulePanel( globals );

		const clearButton = tree.children.find(
			( child ) => child && child.type === 'button'
		);
		expect( clearButton ).toBeDefined();
		expect( clearButton.props.type ).toBe( 'button' );
	} );

	test( 'an ancestor freeze replaces the editable days input with the server-formatted status text, verbatim', () => {
		const globals = buildGlobals( {
			days: '6',
			daysStatusText: '365 days — locked by Network',
		} );

		const tree = apsSchedulePanel( globals );

		expect( findById( tree, 'aps-schedule-days' ) ).toBeNull();

		const status = findById( tree, 'aps-schedule-days-status' );
		expect( status ).not.toBeNull();
		expect( status.children[ 0 ].children[ 0 ] ).toBe(
			'365 days — locked by Network'
		);
	} );

	test( 'no ancestor freeze renders the editable days input, not the status text', () => {
		const globals = buildGlobals( { days: '6' } );

		const tree = apsSchedulePanel( globals );

		expect( findById( tree, 'aps-schedule-days-status' ) ).toBeNull();
		expect( findById( tree, 'aps-schedule-days' ) ).not.toBeNull();
	} );
} );

describe( 'schedule-panel: writes go through post meta, never a computed epoch', () => {
	test( 'typing a date dispatches the RAW string to the local-input meta key, with no Date construction', () => {
		const globals = buildGlobals();

		const tree = apsSchedulePanel( globals );
		const dateInput = findById( tree, 'aps-schedule-date' );

		dateInput.props.onChange( { target: { value: '2027-06-15T08:45' } } );

		expect( globals.wp.data.dispatch ).toHaveBeenCalledWith( 'core/editor' );
		expect( globals._editPostSpy ).toHaveBeenCalledWith( {
			meta: {
				[ LOCAL_INPUT_KEY ]: '2027-06-15T08:45',
				[ CLEAR_FLAG_KEY ]: false,
			},
		} );
	} );

	test( 'a non-empty days value dispatches it as a Number', () => {
		const globals = buildGlobals();

		const tree = apsSchedulePanel( globals );
		const daysInput = findById( tree, 'aps-schedule-days' );

		daysInput.props.onChange( { target: { value: '6' } } );

		expect( globals._editPostSpy ).toHaveBeenCalledWith( {
			meta: { [ DAYS_KEY ]: 6 },
		} );
	} );

	test( 'an emptied days value dispatches null — WordPress\'s own "null deletes this meta value" REST convention', () => {
		const globals = buildGlobals( { days: '6' } );

		const tree = apsSchedulePanel( globals );
		const daysInput = findById( tree, 'aps-schedule-days' );

		daysInput.props.onChange( { target: { value: '' } } );

		expect( globals._editPostSpy ).toHaveBeenCalledWith( {
			meta: { [ DAYS_KEY ]: null },
		} );
	} );

	test( 'Clear dispatches the clear-flag, drops the days override, AND resets the local-input key', () => {
		const globals = buildGlobals( { exactDate: '2027-03-03T10:00', days: '6' } );

		const tree = apsSchedulePanel( globals );
		const clearButton = tree.children.find(
			( child ) => child && child.type === 'button'
		);

		clearButton.props.onClick();

		expect( globals._editPostSpy ).toHaveBeenCalledWith( {
			meta: {
				[ CLEAR_FLAG_KEY ]: true,
				[ LOCAL_INPUT_KEY ]: '',
				[ DAYS_KEY ]: null,
			},
		} );
		expect( globals._editPostSpy ).toHaveBeenCalledTimes( 1 );
	} );
} );

describe( 'schedule-panel: Clear and a typed date are mutually exclusive', () => {
	test( 'typing a date after Clear explicitly un-sets the clear-flag in the same dispatch', () => {
		const globals = buildGlobals( { exactDate: '2027-03-03T10:00', days: '6' } );

		const tree = apsSchedulePanel( globals );
		const clearButton = tree.children.find(
			( child ) => child && child.type === 'button'
		);
		const dateInput = findById( tree, 'aps-schedule-date' );

		clearButton.props.onClick();
		dateInput.props.onChange( { target: { value: '2027-07-01T09:00' } } );

		// The date-change dispatch must not leave a stale `true` clear-flag
		// for MetaRegistrar's independent listeners to race against.
		expect( globals._editPostSpy ).toHaveBeenLastCalledWith( {
			meta: {
				[ LOCAL_INPUT_KEY ]: '2027-07-01T09:00',
				[ CLEAR_FLAG_KEY ]: false,
			},
		} );
	} );

	test( 'clicking Clear after typing a date explicitly resets the local-input key in the same dispatch', () => {
		const globals = buildGlobals();

		const tree = apsSchedulePanel( globals );
		const dateInput = findById( tree, 'aps-schedule-date' );
		const clearButton = tree.children.find(
			( child ) => child && child.type === 'button'
		);

		dateInput.props.onChange( { target: { value: '2027-07-01T09:00' } } );
		clearButton.props.onClick();

		// The Clear dispatch must not leave a stale typed date for
		// MetaRegistrar's independent listeners to race against.
		expect( globals._editPostSpy ).toHaveBeenLastCalledWith( {
			meta: {
				[ CLEAR_FLAG_KEY ]: true,
				[ LOCAL_INPUT_KEY ]: '',
				[ DAYS_KEY ]: null,
			},
		} );
	} );
} );

describe( 'schedule-panel: THE TIMEZONE INVARIANT — no offset math in JS, ever', () => {
	const ORIGINAL_TZ = process.env.TZ;

	afterEach( () => {
		process.env.TZ = ORIGINAL_TZ;
	} );

	/**
	 * Run the exact same interaction under a given process (viewer)
	 * timezone and capture what actually got dispatched to the server.
	 *
	 * @param {string} tz A TZ database name, e.g. 'UTC' or 'Pacific/Kiritimati'.
	 * @return {Object} The captured { meta } payload dispatched on change.
	 */
	function dispatchedPayloadUnderTimezone( tz ) {
		process.env.TZ = tz;

		const globals = buildGlobals( { exactDate: '2027-03-03T10:00' } );
		const tree = apsSchedulePanel( globals );
		const dateInput = findById( tree, 'aps-schedule-date' );

		// Sanity check woven into the same run: the SERVER-localized initial
		// value must render byte-for-byte as given, regardless of the
		// viewer's own timezone -- a regression that re-wrapped it in
		// `new Date(...)` for display would diverge here between timezones.
		expect( dateInput.props.value ).toBe( '2027-03-03T10:00' );

		dateInput.props.onChange( { target: { value: '2027-06-15T08:45' } } );

		return globals._editPostSpy.mock.calls[ 0 ][ 0 ];
	}

	test( 'the dispatched payload is byte-for-byte identical whether the viewer is in UTC or 14 hours ahead of it', () => {
		// Pacific/Kiritimati (UTC+14) is the most extreme real timezone —
		// if any code path ever ran the typed string through `new Date()`
		// (which parses a bare "Y-m-d\THH:mm" string as LOCAL time per the
		// ECMAScript spec) and reformatted it for the request, these two
		// runs would diverge. They must not: the panel never constructs a
		// Date from this field at all.
		const underUtc = dispatchedPayloadUnderTimezone( 'UTC' );
		const under14Hours = dispatchedPayloadUnderTimezone( 'Pacific/Kiritimati' );

		expect( underUtc ).toEqual( under14Hours );
		expect( underUtc ).toEqual( {
			meta: {
				[ LOCAL_INPUT_KEY ]: '2027-06-15T08:45',
				[ CLEAR_FLAG_KEY ]: false,
			},
		} );
	} );

	test( 'the site\'s (server-localized) prefilled date survives unchanged under a negative-offset viewer timezone too', () => {
		const underMinus12 = dispatchedPayloadUnderTimezone( 'Etc/GMT+12' );

		expect( underMinus12 ).toEqual( {
			meta: {
				[ LOCAL_INPUT_KEY ]: '2027-06-15T08:45',
				[ CLEAR_FLAG_KEY ]: false,
			},
		} );
	} );
} );

describe( 'schedule-panel: registration', () => {
	test( 'registerPlugin wires a render that reflects live schedule data', () => {
		const globals = buildGlobals();

		apsRegisterSchedulePanel( globals );

		expect( globals.wp.plugins.registerPlugin ).toHaveBeenCalledWith(
			'archive-schedule-panel',
			expect.objectContaining( { render: expect.any( Function ) } )
		);

		const { render } = globals.wp.plugins.registerPlugin.mock.calls[ 0 ][ 1 ];

		expect( render().type ).toBe( 'PluginDocumentSettingPanel' );

		globals.archivedPostStatusSchedule.canSchedule = false;
		expect( render() ).toBeNull();
	} );
} );
