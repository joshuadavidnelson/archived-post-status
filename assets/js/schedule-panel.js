( () => {
	/**
	 * The three REST-registered meta keys this panel writes to. Literal
	 * strings, mirroring the PHP constants they must stay in sync with:
	 * `ArchivedPostStatus\Schedule\MetaRegistrar::META_LOCAL_INPUT` /
	 * `::META_CLEAR_FLAG`, and
	 * `ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::META_DAYS`.
	 *
	 * ⚠️ NO TIMEZONE ARITHMETIC IN JAVASCRIPT. NONE. This panel moves an
	 * opaque wall-clock STRING between the `datetime-local` control and the
	 * server — it never constructs a `Date` from the exact-date field, never
	 * reads an epoch, and never applies an offset. `LOCAL_INPUT_KEY`'s value
	 * is exactly what the browser's own `datetime-local` control produces
	 * ("Y-m-d\TH:i" in whatever locale/format the browser renders, always
	 * ISO-shaped for this input type) and exactly what
	 * `ArchivedPostStatus\Schedule\ScheduleTime::to_timestamp()` parses,
	 * server-side, in the SITE's timezone -- the one place a wall clock
	 * becomes a UTC epoch, per the plan's §5.3 invariant. `MetaRegistrar`'s
	 * `sync_from_local_input()` is the only converter; this file never
	 * duplicates it.
	 */
	const LOCAL_INPUT_KEY = '_aps_schedule_meta_local_input';
	const CLEAR_FLAG_KEY = '_aps_schedule_meta_clear_flag';
	const DAYS_KEY = '_aps_auto_archive_days';

	/**
	 * The block editor's scheduling panel, rendered into the document
	 * settings sidebar.
	 *
	 * Every value here is a plain string/number the panel renders or writes
	 * verbatim — `exactDate` and `outcome` are pre-formatted server-side
	 * (see `Admin\PostEditor::enqueue_schedule_panel()` /
	 * `Admin\ScheduleOutcome::describe()`), so this component does no
	 * formatting, no parsing, and no date math of its own.
	 *
	 * @param {Object} globals Host globals: wp.element/editPost/data/i18n,
	 *                         archivedPostStatusSchedule.
	 * @return {?Object} The element tree, or null when the user cannot
	 *                    schedule this post.
	 */
	function apsSchedulePanel( globals ) {
		const el = globals.wp.element.createElement;
		const useState = globals.wp.element.useState;
		const PluginDocumentSettingPanel =
			globals.wp.editPost.PluginDocumentSettingPanel;
		const __ = globals.wp.i18n.__;
		const data = globals.archivedPostStatusSchedule;

		if ( ! data.canSchedule ) {
			return null;
		}

		const [ dateValue, setDateValue ] = useState( data.exactDate );
		const [ daysValue, setDaysValue ] = useState( data.days );

		const editPost = ( meta ) => {
			globals.wp.data.dispatch( 'core/editor' ).editPost( { meta } );
		};

		// Clear and a typed date are mutually exclusive outcomes, so each
		// dispatch below explicitly pins the OTHER channel's value too —
		// never leaving it to whatever the post's meta already holds from
		// an earlier edit in the same session. `sync_from_local_input()`
		// and `sync_from_clear_flag()` are independent MetaRegistrar
		// listeners with no ordering contract between them; without this,
		// clicking Clear then typing a date (or the reverse) without an
		// intervening save would ride both a stale clear-flag/local-input
		// value into the same REST write, and whichever listener happened
		// to run last would silently win.
		const onDateChange = ( event ) => {
			const value = event.target.value;
			setDateValue( value );
			editPost( { [ LOCAL_INPUT_KEY ]: value, [ CLEAR_FLAG_KEY ]: false } );
		};

		const onDaysChange = ( event ) => {
			const value = event.target.value;
			setDaysValue( value );
			editPost( {
				[ DAYS_KEY ]: '' === value ? null : Number( value ),
			} );
		};

		const onClear = () => {
			setDateValue( '' );
			setDaysValue( '' );
			editPost( {
				[ CLEAR_FLAG_KEY ]: true,
				[ LOCAL_INPUT_KEY ]: '',
				[ DAYS_KEY ]: null,
			} );
		};

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'archived-post-status-schedule',
				title: __( 'Archive Schedule', 'archived-post-status' ),
				className: 'aps-schedule-panel',
			},
			el(
				'p',
				{ id: 'aps-schedule-outcome' },
				data.outcome
			),
			el(
				'p',
				{},
				el(
					'label',
					{ htmlFor: 'aps-schedule-date' },
					__( 'Exact date', 'archived-post-status' )
				),
				el( 'br' ),
				el( 'input', {
					type: 'datetime-local',
					id: 'aps-schedule-date',
					value: dateValue,
					'aria-describedby': 'aps-schedule-outcome',
					onChange: onDateChange,
				} )
			),
			el(
				'p',
				{},
				el(
					'label',
					{ htmlFor: 'aps-schedule-days' },
					__(
						'Auto archive after (days)',
						'archived-post-status'
					)
				),
				el( 'br' ),
				// An ancestor's Locked/Off state (data.daysStatusText,
				// pre-formatted server-side to match CascadeField's own
				// wording exactly) replaces the editable input entirely —
				// never rendered alongside it, so there is no control left
				// for a frozen value to silently accept input into.
				data.daysStatusText
					? el(
							'p',
							{ id: 'aps-schedule-days-status' },
							el( 'strong', {}, data.daysStatusText )
					  )
					: el( 'input', {
							type: 'number',
							id: 'aps-schedule-days',
							min: '1',
							step: '1',
							value: daysValue,
							onChange: onDaysChange,
					  } )
			),
			el(
				'button',
				{ type: 'button', onClick: onClear },
				__( 'Clear schedule', 'archived-post-status' )
			)
		);
	}

	/**
	 * Register the schedule-panel editor plugin.
	 *
	 * @param {Object} globals Host globals (see apsSchedulePanel).
	 */
	function apsRegisterSchedulePanel( globals ) {
		globals.wp.plugins.registerPlugin( 'archive-schedule-panel', {
			render: () => apsSchedulePanel( globals ),
		} );
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = {
			apsSchedulePanel,
			apsRegisterSchedulePanel,
			LOCAL_INPUT_KEY,
			CLEAR_FLAG_KEY,
			DAYS_KEY,
		};
	} else {
		/* istanbul ignore next -- browser bootstrap; covered by the Playwright editor specs. */
		apsRegisterSchedulePanel( window );
	}
} )();
