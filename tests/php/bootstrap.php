<?php
/**
 * Bootsrap file for tests.
 *
 * @package ArchivedPostStatus
 */

require_once __DIR__ . '/../../vendor/autoload.php';

WP_Mock::setUsePatchwork( true );
WP_Mock::bootstrap();

define( 'PLUGIN_PATH', dirname( __DIR__, 2 ) );

require_once __DIR__ . '/includes/common.php';
require_once __DIR__ . '/includes/TestCase.php';

// Load plugin files.
require_once PLUGIN_PATH . '/src/archived-post-status.php';
