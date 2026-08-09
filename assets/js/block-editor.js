( () => {
	/**
	 * Block editor Archive button, rendered into the post-status panel.
	 *
	 * The server side (Admin\PostEditor) enqueues this for supported post types
	 * and localizes `archivedPostStatus.canArchive` / `.archiveUrl`.
	 *
	 * @param {Object} globals Host globals: wp.element/plugins/editPost/i18n,
	 *                         archivedPostStatus, confirm.
	 * @return {?Object} The element tree, or null when the user cannot archive.
	 */
	function apsArchiveButton( globals ) {
		const el = globals.wp.element.createElement;
		const PluginPostStatusInfo = globals.wp.editPost.PluginPostStatusInfo;
		const __ = globals.wp.i18n.__;

		if ( ! globals.archivedPostStatus.canArchive ) {
			return null;
		}

		return el(
			PluginPostStatusInfo,
			{},
			el(
				'a',
				{
					className:
						'components-button editor-post-archive is-destructive is-primary',
					href: globals.archivedPostStatus.archiveUrl,
					style: {
						margin: '10px auto 0 auto',
						width: 'auto',
						minWidth: '100%',
						textAlign: 'center',
						alignContent: 'center',
						justifyContent: 'center',
					},
					onClick( event ) {
						if (
							! globals.confirm(
								__(
									'Are you sure you want to archive this post?',
									'archived-post-status'
								)
							)
						) {
							event.preventDefault();
						}
					},
				},
				__( 'Archive', 'archived-post-status' )
			)
		);
	}

	/**
	 * Register the archive-button editor plugin.
	 *
	 * @param {Object} globals Host globals (see apsArchiveButton).
	 */
	function apsRegisterArchiveButton( globals ) {
		globals.wp.plugins.registerPlugin( 'archive-button', {
			render: () => apsArchiveButton( globals ),
		} );
	}

	if ( typeof module === 'object' && module.exports ) {
		module.exports = { apsArchiveButton, apsRegisterArchiveButton };
	} else {
		/* istanbul ignore next -- browser bootstrap; covered by the Playwright editor specs. */
		apsRegisterArchiveButton( window );
	}
} )();
