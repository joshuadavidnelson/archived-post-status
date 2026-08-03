( () => {
	/**
	 * Warn before deactivating the plugin while archived content exists.
	 *
	 * The server side (Admin\PluginScreen) enqueues this on plugins.php only
	 * and localizes `archivedPostStatus.hasArchivedPosts` +
	 * `.isNetworkAdmin`. Deactivating unregisters the archived status,
	 * hiding that content until the plugin is reactivated — the confirm
	 * gives the user a chance to unarchive first.
	 *
	 * Two entry points trigger the same confirm: the plugin row's
	 * individual Deactivate link, and the plugins list's Bulk Actions ->
	 * Deactivate submit — the latter only when this plugin's row is among
	 * the checked items.
	 *
	 * On Network Admin's Plugins screen a single site can't answer "does
	 * archived content exist on this network?", so the server skips that
	 * query and localizes `isNetworkAdmin` as `true` instead; there, this
	 * always warns with a generalized message regardless of
	 * `hasArchivedPosts`.
	 *
	 * @param {Document} doc     Document to query for the plugin row.
	 * @param {Object}   globals Host globals: wp.i18n, archivedPostStatus, confirm.
	 * @return {?Function} The bound click handler, or null when the row is absent.
	 */
	function apsBindDeactivationWarning( doc, globals ) {
		const row = doc.querySelector( 'tr[data-slug="archived-post-status"]' );
		const deactivateLink = row && row.querySelector( '.deactivate a' );

		if ( ! deactivateLink ) {
			return null;
		}

		/**
		 * Build the confirm() message for the current screen.
		 *
		 * @return {string} The translated warning.
		 */
		const getWarningMessage = () => {
			if ( globals.archivedPostStatus.isNetworkAdmin ) {
				// translators: warning shown on Network Admin's Plugins screen, where no single site can answer for the whole network.
				return globals.wp.i18n.__(
					'Warning! Deactivating Archived Post Status could impact content visibility. Any site on this network with archived content will no longer be able to access it.',
					'archived-post-status'
				);
			}

			// translators: warning about deactivating the plugin with archived content.
			return globals.wp.i18n.__(
				"Warning! Deactivating this plugin will remove the 'archive' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.",
				'archived-post-status'
			);
		};

		/**
		 * Whether this screen should confirm before deactivation at all.
		 *
		 * Network Admin has no per-site answer to "is there archived
		 * content?", so it always warns; a single site warns only when the
		 * localized existence check found archived content.
		 *
		 * @return {boolean}
		 */
		const shouldWarn = () =>
			globals.archivedPostStatus.isNetworkAdmin ||
			globals.archivedPostStatus.hasArchivedPosts;

		/**
		 * Confirm with the user when this screen warrants it.
		 *
		 * @return {boolean} False only when a warranted confirm was dismissed.
		 */
		const confirmDeactivation = () =>
			! shouldWarn() || globals.confirm( getWarningMessage() );

		const handler = ( event ) => {
			if ( ! confirmDeactivation() ) {
				event.preventDefault();
			}
		};

		deactivateLink.addEventListener( 'click', handler );

		// Bulk Actions -> Deactivate submits the whole plugins list form
		// rather than following the row's individual link, so it needs its
		// own listener. Only fires the same confirm when this plugin's row
		// checkbox is among the ones checked and the chosen bulk action is
		// actually "Deactivate" — any other bulk action, or a submission
		// that doesn't include this plugin, passes through untouched.
		const checkbox = row.querySelector( 'input[type="checkbox"]' );
		const bulkForm = doc.getElementById( 'bulk-action-form' );

		if ( checkbox && bulkForm ) {
			bulkForm.addEventListener( 'submit', ( event ) => {
				if (
					checkbox.checked &&
					'deactivate-selected' === apsCurrentBulkAction( bulkForm ) &&
					! confirmDeactivation()
				) {
					event.preventDefault();
				}
			} );
		}

		return handler;
	}

	/**
	 * Read the bulk action actually being submitted.
	 *
	 * The plugins list table always renders two bulk-action dropdowns
	 * sharing one `#bulk-action-form` — `action` for the top tablenav,
	 * `action2` for the bottom — each defaulting to `-1` until the user
	 * picks something. This mirrors WordPress core's own bulk-action
	 * precedence (`current_action()`): `action` wins whenever it isn't the
	 * `-1` placeholder, otherwise `action2` decides.
	 *
	 * @param {HTMLFormElement} form The plugins list bulk-action form.
	 * @return {string} The selected bulk action, or `-1` when none is chosen.
	 */
	function apsCurrentBulkAction( form ) {
		const { action, action2 } = form.elements;

		return '-1' !== action.value ? action.value : action2.value;
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = { apsBindDeactivationWarning };
	} else {
		/* istanbul ignore next -- browser bootstrap; covered by the Playwright deactivation-warning spec. */
		document.addEventListener( 'DOMContentLoaded', () =>
			apsBindDeactivationWarning( document, window )
		);
	}
} )();
