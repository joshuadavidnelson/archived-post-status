document.addEventListener( 'DOMContentLoaded', function() {

    const deactivateLink = document.querySelector( 'tr[data-slug="archived-post-status"] .deactivate a');

    if ( deactivateLink ) {
        deactivateLink.addEventListener( 'click', (e) => {
            // translators: warning about deactivating the plugin with archived content.
            var message = wp.i18n.__( 'Warning! Deactivating this plugin will remove the \'archive\' status and all content in that status will be hidden. To keep that content visible, unarchive it first and then deactivate this plugin.', 'archived-post-status' );

            if ( archivedPostStatus.hasArchivedPosts && ! confirm( message ) ) {
                e.preventDefault();
            }
        });
    }
});
