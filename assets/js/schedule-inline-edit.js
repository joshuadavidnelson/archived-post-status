( () => {
	/**
	 * Populate Quick Edit's schedule fields from the row being edited.
	 *
	 * WordPress does NOT pre-populate Quick Edit's custom fields: core's own
	 * `inlineEditPost.edit()` only copies its OWN built-in columns out of the
	 * row's DOM. `Admin\ScheduleColumnCellRenderer` renders a hidden
	 * `.aps-schedule-inline-data` span into every row's cell carrying the
	 * post's current schedule as a `data-local` attribute; this is the only
	 * place that value is read.
	 *
	 * ⚠️ NO TIMEZONE ARITHMETIC IN JAVASCRIPT. NONE. `data-local` is EXACTLY
	 * the string `ArchivedPostStatus\Schedule\ScheduleTime::to_local()`
	 * produced, server-side, in the site's own timezone (plan §5.3's one
	 * boundary). This function only ever copies that string, verbatim, into
	 * the `datetime-local` input's `value` — it never constructs a `Date`
	 * from it, never reads an epoch, never applies an offset. Whatever the
	 * user leaves in the field is submitted back exactly as typed, and
	 * `ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp()` (the same
	 * class, the opposite direction {@see ScheduleMetaBox} already uses) is
	 * the only place it becomes a UTC epoch again.
	 *
	 * The template row is a single shared element WordPress reuses for every
	 * Quick Edit activation, so both fields are reset explicitly on every
	 * call — a stale value or a checked Clear box from a PREVIOUS row must
	 * never leak into this one.
	 *
	 * @param {Document} doc    Document to query for the row and its data span.
	 * @param {number}   postId The post whose row is being edited.
	 * @return {void}
	 */
	function apsPopulateQuickEditRow( doc, postId ) {
		const row = doc.getElementById( 'post-' + postId );
		const editRow = doc.getElementById( 'edit-' + postId );

		if ( ! editRow ) {
			return;
		}

		const dataSpan = row
			? row.querySelector( '.aps-schedule-inline-data' )
			: null;
		const dateField = editRow.querySelector(
			'input[name="aps_schedule_quick_date"]'
		);
		const clearField = editRow.querySelector(
			'input[name="aps_schedule_quick_clear"]'
		);

		if ( dateField ) {
			dateField.value =
				dataSpan && dataSpan.dataset ? dataSpan.dataset.local || '' : '';
		}

		if ( clearField ) {
			clearField.checked = false;
		}
	}

	/**
	 * Resolve the post id `inlineEditPost.edit()` was called with, whatever
	 * shape it arrives in: a plain post id (WordPress passes one when core
	 * itself re-opens a row), or the row-actions link `inlineEditPost.edit`
	 * click handlers bind to.
	 *
	 * @param {number|string|Element} id The value core's edit() received.
	 * @return {number} The post id, or 0 when it cannot be resolved.
	 */
	function apsResolvePostId( id ) {
		if ( 'number' === typeof id ) {
			return id;
		}

		if ( 'string' === typeof id ) {
			return parseInt( id, 10 ) || 0;
		}

		if ( id && 'function' === typeof id.closest ) {
			const row = id.closest( 'tr' );
			const match = row && row.id ? row.id.match( /(\d+)$/ ) : null;

			return match ? parseInt( match[ 1 ], 10 ) : 0;
		}

		return 0;
	}

	/**
	 * Wrap WordPress's own `inlineEditPost.edit()` so this plugin's row
	 * hydration runs immediately after core populates its own fields.
	 *
	 * Guarded for `inlineEditPost` being absent: it is only defined on
	 * screens with Quick Edit support, not on every admin screen this script
	 * could conceivably load on.
	 *
	 * @param {Object} globals Host globals: document, inlineEditPost.
	 * @return {void}
	 */
	function apsWireQuickEdit( globals ) {
		if ( ! globals.inlineEditPost ) {
			return;
		}

		const original = globals.inlineEditPost.edit;

		globals.inlineEditPost.edit = function ( id ) {
			const result = original.apply( this, arguments );
			const postId = apsResolvePostId( id );

			if ( postId ) {
				apsPopulateQuickEditRow( globals.document, postId );
			}

			return result;
		};
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = {
			apsPopulateQuickEditRow,
			apsResolvePostId,
			apsWireQuickEdit,
		};
	} else {
		/* istanbul ignore next -- browser bootstrap; covered by the Playwright editor specs. */
		apsWireQuickEdit( window );
	}
} )();
