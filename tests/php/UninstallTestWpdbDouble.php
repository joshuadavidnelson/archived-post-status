<?php
/**
 * Test double for the WordPress `wpdb` global used by UninstallTest.
 *
 * Captures `prepare()` and `query()` calls so the test can assert that
 * uninstall.php issues exactly one DELETE against the postmeta table with
 * the `_aps_archive_meta_%` LIKE pattern. Subclasses the bootstrap-defined
 * `wpdb` stub so type checks and `$wpdb->postmeta` lookups continue to work.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\Tests;

if ( ! class_exists( 'wpdb' ) ) {
	// Defensive — tests/php/bootstrap.php should have defined this already,
	// but separate-process tests start cold and the autoloader doesn't see
	// our test bootstrap until WP_Mock::bootstrap() runs.
	require_once dirname( __DIR__ ) . '/php/bootstrap.php';
}

/**
 * Lightweight $wpdb double for uninstall.php's single prepared DELETE.
 */
class UninstallTestWpdbDouble extends \wpdb {

	/** @var int Number of times query() was invoked. */
	public int $query_calls = 0;

	/** @var array<int, array{query: string, args: array<int, mixed>}> */
	public array $prepared_queries = array();

	/**
	 * Explicit declaration so PHP 8.4 doesn't emit a dynamic-property
	 * deprecation when the constructor populates it. The bootstrap-
	 * defined wpdb stub declares `$posts` but not `$postmeta`.
	 *
	 * @var string
	 */
	public string $postmeta = '';

	public function __construct() {
		parent::__construct();
		$this->postmeta = $this->prefix . 'postmeta';
	}

	/**
	 * Capture the query + bound args for later assertion. Returns the query
	 * string as-is — uninstall.php hands the result to `$wpdb->query()`
	 * directly, so the return value just has to be a non-falsy string.
	 *
	 * @param string $query Prepared query template.
	 * @param mixed  ...$args Bound parameters.
	 * @return string The query string for chaining into ->query().
	 */
	public function prepare( $query, ...$args ) {
		$this->prepared_queries[] = array(
			'query' => $query,
			'args'  => $args,
		);
		return $query;
	}

	/**
	 * Record that query() was called. Return value is unused by uninstall.php.
	 *
	 * @param string $query SQL to execute.
	 * @return int Synthetic affected-rows count.
	 */
	public function query( $query ) {
		++$this->query_calls;
		return 0;
	}

	/**
	 * Identity escape for LIKE patterns. The real wpdb::esc_like backslash-
	 * escapes `_`, `%`, and `\` so user input cannot expand wildcards. In
	 * production our input is a literal namespace prefix (`_aps_archive_meta_`)
	 * — escaping it doesn't change the matched set, only the on-wire form
	 * MySQL sees. For asserting "did uninstall.php target the right
	 * namespace?" we want to compare against the logical pattern
	 * `_aps_archive_meta_%`, not the escaped form, so this double is an
	 * identity pass-through.
	 *
	 * @param string $text Text that would have been escaped for LIKE.
	 * @return string The text unchanged.
	 */
	public function esc_like( $text ) {
		return $text;
	}
}
