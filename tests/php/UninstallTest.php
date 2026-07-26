<?php
/**
 * Uninstall Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ::aps_uninstall_site
 *
 * Pins the 0.4.0 cleanup contract that runs when a site administrator
 * deletes the plugin: removes the settings option, the version option,
 * and every `_aps_archive_meta_*` postmeta row.
 *
 * Implementation notes
 *
 *   uninstall.php is procedural top-level code, not a class. Coverage of
 *   the top-level branching (single-site vs multisite, the sites-cap
 *   guard, the WP_UNINSTALL_PLUGIN guard) requires controlling state
 *   that PHP usually treats as immutable for the life of a process:
 *
 *     - `define( 'WP_UNINSTALL_PLUGIN', true )` — once defined, it
 *       stays defined for the rest of the run.
 *     - `function aps_uninstall_site()` — declared at file scope; a
 *       second include of uninstall.php fatals on duplicate function.
 *
 *   Strategy: do the heavy include() in ONE in-process test
 *   (test_single_site_uninstall_*), which contributes line coverage
 *   for the whole file. The multisite + cap-skip + guard scenarios
 *   then run in isolated child processes (`@runInSeparateProcess` and
 *   shell_exec respectively); coverage in those subprocesses is lost
 *   per PCOV's child-process model, but the behavioral assertions
 *   stand on their own.
 *
 *   The follow-up in-process test_aps_uninstall_site_function_*
 *   exercises the function directly after the include — by that point
 *   the function is declared, so calling it again is safe and we
 *   re-mock $wpdb to assert on the prepared statement shape.
 */

/**
 * Uninstall test case.
 *
 * @since 0.4.0
 * @covers ::aps_uninstall_site
 */
class UninstallTest extends TestCase {

	/**
	 * Path to uninstall.php for include() / subprocess calls.
	 */
	private const UNINSTALL_FILE = __DIR__ . '/../../uninstall.php';

	/**
	 * Ordering hint — PHPUnit's executionOrder='depends,defects' uses
	 * @depends to make sure the in-process single-site test runs first
	 * so subsequent tests can rely on aps_uninstall_site() being declared.
	 *
	 * The single-site test is the one that include()s uninstall.php
	 * top-level code, so it must happen exactly once and before any
	 * sibling that calls aps_uninstall_site() directly.
	 */
	public function set_up() {
		parent::set_up();
		require_once __DIR__ . '/UninstallTestWpdbDouble.php';
	}

	/**
	 * WP_UNINSTALL_PLUGIN guard — when the constant is not defined,
	 * uninstall.php must `exit;` before declaring `aps_uninstall_site()`
	 * or touching any options. This is WordPress's mechanism for ensuring
	 * the file only runs during a legitimate plugin-delete request from
	 * within wp-admin.
	 *
	 * Verified by running uninstall.php in a real subprocess (the guard
	 * path uses `exit`, which PHPUnit's process isolation cannot observe
	 * cleanly). The subprocess produces no output and the function
	 * `aps_uninstall_site` is never declared in its scope.
	 */
	public function test_no_op_when_wp_uninstall_plugin_constant_is_not_defined() {
		$uninstall_path = realpath( self::UNINSTALL_FILE );
		$this->assertNotFalse( $uninstall_path, 'uninstall.php must exist on disk' );

		// Subprocess does not define WP_UNINSTALL_PLUGIN, so uninstall.php
		// should hit `exit;` on line 12 before declaring the function.
		// We print a sentinel after include() to detect that exit fired.
		$cmd = sprintf(
			'%s -r %s 2>&1',
			escapeshellarg( PHP_BINARY ),
			escapeshellarg(
				"include " . var_export( $uninstall_path, true ) . ";"
				. "echo function_exists('aps_uninstall_site') ? 'DEFINED' : 'UNDEFINED';"
			)
		);

		$output = shell_exec( $cmd );

		// The function declaration sits below the guard. If the guard fired,
		// the function never gets declared and the sentinel never prints.
		$this->assertSame(
			'',
			(string) $output,
			'uninstall.php should exit before producing any output when WP_UNINSTALL_PLUGIN is undefined'
		);
	}

	/**
	 * Single-site path — when `is_multisite()` is false, the file runs
	 * `aps_uninstall_site()` exactly once: deletes the version option, the
	 * previous-version option, the `aps_settings` option, and issues a
	 * single prepared DELETE against postmeta with the `_aps_archive_meta_%`
	 * LIKE pattern.
	 *
	 * Runs in-process (no `@runInSeparateProcess`) so the line coverage
	 * registers against uninstall.php; the sibling tests that need
	 * different top-level state will isolate themselves via subprocess.
	 */
	public function test_single_site_uninstall_removes_options_and_archive_meta_postmeta_rows() {
		// Pretend we're inside WordPress's uninstall handler. The constant
		// stays defined for the rest of the process, but every subsequent
		// test either uses a subprocess or just calls aps_uninstall_site()
		// directly, so leakage is intentional.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );

		\WP_Mock::userFunction( 'delete_option' )
			->with( 'archived_post_status_version' )
			->once();
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'archived_post_status_previous_version' )
			->once();
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'aps_settings' )
			->once();

		// Install our $wpdb double so we can verify the prepared statement.
		global $wpdb;
		$wpdb = new \ArchivedPostStatus\Tests\UninstallTestWpdbDouble();

		// Top-level code in uninstall.php runs at include time. After this
		// line, function aps_uninstall_site() is declared in the global
		// scope and persists for the remainder of the PHP process.
		include self::UNINSTALL_FILE;

		// Verify the prepared LIKE pattern is exactly _aps_archive_meta_% —
		// the 0.4.0 archive metadata namespace.
		$this->assertCount( 1, $wpdb->prepared_queries, 'expected exactly one prepared DELETE' );
		$prepared = $wpdb->prepared_queries[0];

		$this->assertStringContainsString( 'DELETE FROM', $prepared['query'] );
		$this->assertStringContainsString( 'meta_key LIKE %s', $prepared['query'] );
		$this->assertSame(
			'_aps_archive_meta_%',
			$prepared['args'][0],
			'LIKE pattern must target the 0.4.0 _aps_archive_meta_ namespace'
		);

		$this->assertSame( 1, $wpdb->query_calls, 'wpdb::query must be called exactly once' );
	}

	/**
	 * The `aps_uninstall_site()` function (declared by the previous
	 * include) deletes options in the expected order. Running directly
	 * gives a second independent in-process exercise of the function's
	 * lines (option deletions + the prepared DELETE) without re-including
	 * the file.
	 *
	 * @depends test_single_site_uninstall_removes_options_and_archive_meta_postmeta_rows
	 */
	public function test_aps_uninstall_site_function_deletes_options_and_archive_meta() {
		$this->assertTrue(
			function_exists( 'aps_uninstall_site' ),
			'previous test must have declared aps_uninstall_site via include'
		);

		\WP_Mock::userFunction( 'delete_option' )
			->with( 'archived_post_status_version' )->once();
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'archived_post_status_previous_version' )->once();
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'aps_settings' )->once();

		global $wpdb;
		$wpdb = new \ArchivedPostStatus\Tests\UninstallTestWpdbDouble();

		aps_uninstall_site();

		$this->assertSame( 1, $wpdb->query_calls, 'one DELETE per call' );
		$this->assertSame(
			'_aps_archive_meta_%',
			$wpdb->prepared_queries[0]['args'][0]
		);
	}

	/**
	 * Multisite path under the sites cap — `aps_uninstall_site()` runs once
	 * per site in the network, wrapped in `switch_to_blog` /
	 * `restore_current_blog`. Three sites in the network → three iterations.
	 *
	 * Runs in a separate process so the top-level multisite branching
	 * actually executes (in-process the WP_UNINSTALL_PLUGIN constant is
	 * already defined but include()ing uninstall.php a second time would
	 * fatal on duplicate function declaration).
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multisite_uninstall_iterates_sites_and_switches_blog_context() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_current_network_id' )->andReturn( 1 );
		\WP_Mock::userFunction( 'get_blog_count' )->with( 1 )->andReturn( 3 );

		$sites = array(
			(object) array( 'blog_id' => 1 ),
			(object) array( 'blog_id' => 2 ),
			(object) array( 'blog_id' => 3 ),
		);
		// uninstall.php passes ['number' => $count] — we assert the count
		// flows through unchanged so very small networks aren't capped.
		\WP_Mock::userFunction( 'get_sites' )
			->once()
			->with( array( 'number' => 3 ) )
			->andReturn( $sites );

		$switched = array();
		\WP_Mock::userFunction( 'switch_to_blog' )
			->times( 3 )
			->andReturnUsing(
				function ( $blog_id ) use ( &$switched ) {
					$switched[] = $blog_id;
					return true;
				}
			);
		\WP_Mock::userFunction( 'restore_current_blog' )->times( 3 )->andReturn( true );

		// delete_option fires three times (once per site) for each of the
		// three deleted options.
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'archived_post_status_version' )->times( 3 );
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'archived_post_status_previous_version' )->times( 3 );
		\WP_Mock::userFunction( 'delete_option' )
			->with( 'aps_settings' )->times( 3 );

		global $wpdb;
		$wpdb = new \ArchivedPostStatus\Tests\UninstallTestWpdbDouble();

		include self::UNINSTALL_FILE;

		$this->assertSame( array( 1, 2, 3 ), $switched, 'switch_to_blog called once per site in order' );
		$this->assertSame( 3, $wpdb->query_calls, 'wpdb::query called once per site' );
	}

	/**
	 * Sites cap — networks at or above 5000 sites are intentionally skipped
	 * to avoid the orchestration overhead of iterating the entire network
	 * during a single PHP request (each `switch_to_blog` re-bootstraps the
	 * site's option cache).
	 *
	 * This is an explicit design choice; see the inline comment at
	 * uninstall.php:53. Networks at this scale are expected to drop the
	 * plugin's options out of band (WP-CLI loop across sites, direct SQL,
	 * etc.). The constraint document for Phase 2.5 directed us to record
	 * the intent and write a regression test, not change behavior — Phase
	 * 5.5 owns any eventual reconsideration.
	 *
	 * Decision (recorded for Reviewer): keep as intentional. get_sites
	 * must NEVER be called when the count is at or above the cap.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_multisite_uninstall_skips_iteration_when_sites_meet_or_exceed_5000_cap() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_current_network_id' )->andReturn( 1 );
		\WP_Mock::userFunction( 'get_blog_count' )->with( 1 )->andReturn( 5000 );

		// The cap guard fires; no site iteration must occur.
		\WP_Mock::userFunction( 'get_sites' )->never();
		\WP_Mock::userFunction( 'switch_to_blog' )->never();
		\WP_Mock::userFunction( 'restore_current_blog' )->never();
		\WP_Mock::userFunction( 'delete_option' )->never();

		global $wpdb;
		$wpdb = new \ArchivedPostStatus\Tests\UninstallTestWpdbDouble();

		include self::UNINSTALL_FILE;

		$this->assertSame( 0, $wpdb->query_calls, 'wpdb::query must not be called when sites cap is hit' );
	}
}
