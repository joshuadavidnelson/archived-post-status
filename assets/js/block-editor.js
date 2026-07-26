(() => {
    const wp = window.wp;
    const el = wp.element.createElement;
    const registerPlugin = wp.plugins.registerPlugin;
    const PluginPostStatusInfo = wp.editPost.PluginPostStatusInfo;
    const __ = wp.i18n.__;

    function archiveButton() {
        // Check if the current user can edit posts
        if ( ! archivedPostStatus.canArchive ) {
            return null;
        }

        return el(
            PluginPostStatusInfo,
            {},
            el(
                'a',
                {
                    className: 'components-button editor-post-archive is-destructive is-primary',
                    href: archivedPostStatus.archiveUrl,
                    style: {
                        'margin': '10px auto 0 auto',
                        'width': 'auto',
                        'minWidth': '100%',
                        'textAlign': 'center',
                        'alignContent': 'center',
                        'justifyContent': 'center',
                    },
                    onClick(event) {
                        if ( ! window.confirm( __( 'Are you sure you want to archive this post?', 'archived-post-status' ) ) ) {
                            event.preventDefault();
                        }
                    }
                },
                __( 'Archive', 'archived-post-status' )
            )
        );
    }

    registerPlugin( 'archive-button', {
        render: archiveButton
    });
})();
