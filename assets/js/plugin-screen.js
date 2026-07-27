( () => {
	/**
	 * Warn before deactivating the plugin while archived content exists.
	 *
	 * The server side (Admin\PluginScreen) enqueues this on plugins.php only and
	 * localizes `archivedPostStatus.hasArchivedPosts`. Deactivating unregisters
	 * the archived status, hiding that content until the plugin is reactivated —
	 * the confirm gives the user a chance to unarchive first.
	 *
	 * @param {Document} doc     Document to query for the plugin row.
	 * @param {Object}   globals Host globals: wp.i18n, archivedPostStatus, confirm.
	 * @return {?Function} The bound click handler, or null when the row is absent.
	 */
	function apsBindDeactivationWarning( doc, globals ) {
		const deactivateLink = doc.querySelector(
			'tr[data-slug="archived-post-status"] .deactivate a'
		);

		if ( ! deactivateLink ) {
			return null;
		}

		const handler = ( event ) => {
			// translators: warning about deactivating the plugin with archived content.
			const message = globals.wp.i18n.__(
				"Warning! Deactivating this plugin will remove the 'archive' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.",
				'archived-post-status'
			);

			if (
				globals.archivedPostStatus.hasArchivedPosts &&
				! globals.confirm( message )
			) {
				event.preventDefault();
			}
		};

		deactivateLink.addEventListener( 'click', handler );

		return handler;
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
