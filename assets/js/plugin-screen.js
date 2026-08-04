( () => {
	/**
	 * Warn before deactivating the plugin while archived content exists.
	 *
	 * The server side (Admin\PluginScreen) enqueues this on plugins.php and
	 * localizes `archivedPostStatus.hasArchivedPosts` + `.isNetworkAdmin`.
	 * Deactivating unregisters the archived status and hides that content, so
	 * the confirm gives the user a chance to unarchive first.
	 *
	 * Two entry points trigger the same confirm: the row's Deactivate link and
	 * the Bulk Actions -> Deactivate submit.
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
		 * Network Admin has no per-site answer to "is there archived content?",
		 * so it always warns.
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

		// Bulk Actions -> Deactivate submits the list form rather than
		// following the row's link, so it needs its own listener.
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
	 * The list table renders two dropdowns sharing one `#bulk-action-form` —
	 * `action` (top) and `action2` (bottom) — each `-1` until the user picks.
	 * Precedence matches core's `current_action()`: `action` wins unless it is
	 * still the placeholder.
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
