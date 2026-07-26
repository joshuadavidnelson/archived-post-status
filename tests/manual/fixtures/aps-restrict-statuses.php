<?php
/**
 * Manual-test mu-plugin: restrict archivable statuses to publish only.
 *
 * Used in the 0.4.0 E2E scenario A1 to verify that `aps_archive_post( <id> )`
 * on a non-publish post is rejected when `aps_archivable_statuses` is
 * narrowed.
 *
 * Drop into `/wp-content/mu-plugins/` for the duration of the test,
 * then remove. Preserved here so the test can be re-run.
 *
 * @package ArchivedPostStatus\TestFixtures
 */

add_filter(
	'aps_archivable_statuses',
	function () {
		return array( 'publish' );
	}
);
