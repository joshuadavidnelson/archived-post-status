<?php
/**
 * Bootsrap file for tests.
 *
 * @package ArchivedPostStatus
 */

require_once __DIR__ . '/../../vendor/autoload.php';

WP_Mock::setUsePatchwork( true );
WP_Mock::bootstrap();

define( 'APS_PLUGIN_PATH', dirname( __DIR__, 2 ) );

// Mock WordPress constants
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

if ( ! defined( 'WEEK_IN_SECONDS' ) ) {
	define( 'WEEK_IN_SECONDS', 604800 );
}

// WP function polyfills.
require_once __DIR__ . '/Support/WpPolyfills.php';

// Mock WordPress classes that might be needed in tests
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public $ID;
		public $post_status;
		public $post_type;
		public $comment_status;
		public $ping_status;
		public $post_title;
		public $post_content;
		public $post_excerpt;
		public $post_name;
		public $post_date;
		public $post_author;

		public function __construct( array $data = [] ) {
			foreach ( $data as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	class WP_Query {
		public $posts = [];
		public $post_count = 0;
		public $found_posts = 0;

		public function __construct( $args = [] ) {
			// Mock constructor
		}

		public function have_posts() {
			return $this->post_count > 0;
		}

		public function the_post() {
			// Mock method
		}
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public $posts;
		public $prefix = 'wp_';

		public function __construct() {
			$this->posts = $this->prefix . 'posts';
		}

		public function prepare( $query, ...$args ) {
			return $query;
		}

		public function get_var( $query ) {
			return 0;
		}
	}
}

require_once __DIR__ . '/includes/TestCase.php';

// Shared test-support traits.
// Loaded here so any test class can `use ArchivedPostStatus\Tests\Support\BoundaryStubs;`
// without each suite re-requiring the file.
require_once __DIR__ . '/Support/BoundaryStubs.php';

// Load plugin files.
require_once APS_PLUGIN_PATH . '/archived-post-status.php';

// aps_run_plugin() at suite start: this fires the production plugin's
// bootstrap pipeline (autoloader + Plugin::hookables() composition) so the
// hookables graph is constructed exactly once for the entire PHPUnit run.
// Tests that inspect the WP_Mock filter / action registry rely on the
// production hook registration having happened by the time `set_up()` runs.
// Calling here — at the file end, after every helper class and WP-Mock
// constant is in scope — keeps the suite-level side effect explicit and
// localized rather than scattered across per-test bootstraps.
//
// A benign wpdb double backs Plugin::upgrade_check()'s pre-0.4.0 content
// probe during this bootstrap run (no real database exists here); tests
// that exercise the probe install their own double per-test.
$GLOBALS['wpdb'] = new class() {
	public $posts = 'wp_posts';

	/**
	 * @param string $query Ignored.
	 * @return null Always empty — the bootstrap site has no content.
	 */
	public function get_var( $query ) {
		return null;
	}
};
aps_run_plugin();
unset( $GLOBALS['wpdb'] );
