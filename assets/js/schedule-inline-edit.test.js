/**
 * Behavior tests for the shipped schedule-inline-edit.js — the module under
 * test is imported, never re-implemented, so a regression in the real
 * source fails here.
 */
const {
	apsPopulateQuickEditRow,
	apsResolvePostId,
	apsWireQuickEdit,
} = require( './schedule-inline-edit' );

/**
 * Render a post-list row plus its (already-open) Quick Edit template row,
 * mirroring what `Admin\ScheduleColumnCellRenderer` / `Admin\ScheduleQuickEdit`
 * actually emit: the hidden `.aps-schedule-inline-data` span inside the
 * visible row's cell, and the date/clear fields inside the `#edit-{id}` row.
 *
 * @param {Object}  [options]
 * @param {number}  [options.postId]
 * @param {?string} [options.localValue]      `data-local` on the hidden span, or null to omit the span entirely.
 * @param {boolean} [options.clearChecked]    Whether the Clear checkbox starts checked.
 * @return {void}
 */
function renderRow( {
	postId = 42,
	localValue = '',
	clearChecked = false,
} = {} ) {
	const dataSpan =
		null === localValue
			? ''
			: `<span class="aps-schedule-inline-data" data-local="${ localValue }"></span>`;

	document.body.innerHTML = `
		<table><tbody>
			<tr id="post-${ postId }">
				<td class="column-aps_scheduled">${ dataSpan }</td>
			</tr>
			<tr id="edit-${ postId }" class="inline-edit-row">
				<td>
					<input type="datetime-local" name="aps_schedule_quick_date" />
					<input type="checkbox" name="aps_schedule_quick_clear" ${
						clearChecked ? 'checked' : ''
					} />
				</td>
			</tr>
		</tbody></table>
	`;
}

describe( 'schedule-inline-edit: apsPopulateQuickEditRow', () => {
	test( 'populates the date field from the row\'s hidden data span', () => {
		renderRow( { postId: 42, localValue: '2027-03-03T14:30' } );

		apsPopulateQuickEditRow( document, 42 );

		const dateField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		);
		expect( dateField.value ).toBe( '2027-03-03T14:30' );
	} );

	test( 'leaves the date field blank when the row has no manual schedule', () => {
		renderRow( { postId: 42, localValue: '' } );

		apsPopulateQuickEditRow( document, 42 );

		const dateField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		);
		expect( dateField.value ).toBe( '' );
	} );

	test( 'leaves the date field blank when the row has no data span at all', () => {
		renderRow( { postId: 42, localValue: null } );

		apsPopulateQuickEditRow( document, 42 );

		const dateField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		);
		expect( dateField.value ).toBe( '' );
	} );

	test( 'resets a previously-checked Clear checkbox -- the template row is shared and reused across activations', () => {
		renderRow( { postId: 42, localValue: '', clearChecked: true } );

		apsPopulateQuickEditRow( document, 42 );

		const clearField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_clear"]'
		);
		expect( clearField.checked ).toBe( false );
	} );

	test( 'does nothing when the edit row is not present', () => {
		document.body.innerHTML = `
			<table><tbody>
				<tr id="post-42">
					<td class="column-aps_scheduled">
						<span class="aps-schedule-inline-data" data-local="2027-03-03T14:30"></span>
					</td>
				</tr>
			</tbody></table>
		`;

		expect( () => apsPopulateQuickEditRow( document, 42 ) ).not.toThrow();
	} );

	test( 'does not throw when the fields themselves are absent from the edit row', () => {
		document.body.innerHTML = `
			<table><tbody>
				<tr id="post-42">
					<td class="column-aps_scheduled">
						<span class="aps-schedule-inline-data" data-local="2027-03-03T14:30"></span>
					</td>
				</tr>
				<tr id="edit-42"><td></td></tr>
			</tbody></table>
		`;

		expect( () => apsPopulateQuickEditRow( document, 42 ) ).not.toThrow();
	} );

	test( 'leaves the date field blank when the edit row exists but the visible post row does not', () => {
		document.body.innerHTML = `
			<table><tbody>
				<tr id="edit-42" class="inline-edit-row">
					<td>
						<input type="datetime-local" name="aps_schedule_quick_date" />
						<input type="checkbox" name="aps_schedule_quick_clear" />
					</td>
				</tr>
			</tbody></table>
		`;

		apsPopulateQuickEditRow( document, 42 );

		const dateField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		);
		expect( dateField.value ).toBe( '' );
	} );
} );

describe( 'schedule-inline-edit: apsResolvePostId', () => {
	test( 'passes a numeric id through unchanged', () => {
		expect( apsResolvePostId( 42 ) ).toBe( 42 );
	} );

	test( 'parses a numeric string', () => {
		expect( apsResolvePostId( '42' ) ).toBe( 42 );
	} );

	test( 'resolves a DOM element to the enclosing row\'s post id', () => {
		document.body.innerHTML = `
			<table><tbody>
				<tr id="post-42"><td><a href="#" class="editinline">Quick Edit</a></td></tr>
			</tbody></table>
		`;
		const link = document.querySelector( 'a.editinline' );

		expect( apsResolvePostId( link ) ).toBe( 42 );
	} );

	test( 'returns 0 for input it cannot resolve', () => {
		expect( apsResolvePostId( {} ) ).toBe( 0 );
		expect( apsResolvePostId( 'not-a-number' ) ).toBe( 0 );
		expect( apsResolvePostId( null ) ).toBe( 0 );
	} );

	test( 'returns 0 for an element with no enclosing row', () => {
		const orphan = document.createElement( 'a' );

		expect( apsResolvePostId( orphan ) ).toBe( 0 );
	} );
} );

describe( 'schedule-inline-edit: apsWireQuickEdit', () => {
	test( 'does nothing when inlineEditPost is absent -- not every screen has it', () => {
		expect( () => apsWireQuickEdit( { document } ) ).not.toThrow();
	} );

	test( 'wraps inlineEditPost.edit(), calling the original AND hydrating the row', () => {
		renderRow( { postId: 42, localValue: '2027-03-03T14:30' } );

		const original = jest.fn().mockReturnValue( 'original-result' );
		const globals = { document, inlineEditPost: { edit: original } };

		apsWireQuickEdit( globals );
		const result = globals.inlineEditPost.edit( 42 );

		expect( original ).toHaveBeenCalledWith( 42 );
		expect( result ).toBe( 'original-result' );

		const dateField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		);
		expect( dateField.value ).toBe( '2027-03-03T14:30' );
	} );

	test( 'calls the original but skips row hydration when the id cannot be resolved', () => {
		renderRow( { postId: 42, localValue: '2027-03-03T14:30' } );

		const original = jest.fn().mockReturnValue( 'original-result' );
		const globals = { document, inlineEditPost: { edit: original } };

		apsWireQuickEdit( globals );
		const result = globals.inlineEditPost.edit( {} );

		expect( original ).toHaveBeenCalledWith( {} );
		expect( result ).toBe( 'original-result' );

		// Post 42's row is untouched -- nothing was resolvable to hydrate.
		const dateField = document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		);
		expect( dateField.value ).toBe( '' );
	} );

	test( 'preserves `this` binding when calling the original function', () => {
		renderRow( { postId: 42 } );

		const inlineEditPost = {
			marker: 'inlineEditPost-instance',
			edit( id ) {
				// A real inlineEditPost.edit() reads `this` internally
				// (e.g. this.getId()) -- the wrapper must not lose it.
				return this.marker;
			},
		};
		const globals = { document, inlineEditPost };

		apsWireQuickEdit( globals );

		expect( globals.inlineEditPost.edit( 42 ) ).toBe(
			'inlineEditPost-instance'
		);
	} );
} );

describe( 'schedule-inline-edit: THE TIMEZONE INVARIANT — no date math in JS, ever', () => {
	const ORIGINAL_TZ = process.env.TZ;

	afterEach( () => {
		process.env.TZ = ORIGINAL_TZ;
	} );

	/**
	 * Populate a fresh row under a given process (viewer) timezone and
	 * return the resulting date field value.
	 *
	 * @param {string} tz A TZ database name, e.g. 'UTC' or 'Pacific/Kiritimati'.
	 * @return {string} The date field's value after population.
	 */
	function populatedValueUnderTimezone( tz ) {
		process.env.TZ = tz;

		renderRow( { postId: 42, localValue: '2027-03-03T14:30' } );
		apsPopulateQuickEditRow( document, 42 );

		return document.querySelector(
			'#edit-42 input[name="aps_schedule_quick_date"]'
		).value;
	}

	test( 'the populated field value is byte-for-byte identical whether the viewer is in UTC or 14 hours ahead of it', () => {
		// Pacific/Kiritimati (UTC+14) is the most extreme real timezone --
		// if this function ever ran the server's local-time string through
		// `new Date()` (which parses a bare "Y-m-d\THH:mm" as LOCAL time)
		// and reformatted it, these two runs would diverge. They must not:
		// this function only ever copies the string.
		const underUtc = populatedValueUnderTimezone( 'UTC' );
		const under14Hours = populatedValueUnderTimezone( 'Pacific/Kiritimati' );

		expect( underUtc ).toBe( under14Hours );
		expect( underUtc ).toBe( '2027-03-03T14:30' );
	} );

	test( 'survives unchanged under a negative-offset viewer timezone too', () => {
		const underMinus12 = populatedValueUnderTimezone( 'Etc/GMT+12' );

		expect( underMinus12 ).toBe( '2027-03-03T14:30' );
	} );
} );
