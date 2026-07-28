<?php
/**
 * Plugin Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Plugin
 *
 * The plugin-deactivation warning lives in the `Admin\PluginScreen`
 * hookable, covered separately by `tests/php/Admin/PluginScreenTest.php`;
 * its composition-root wiring is covered here alongside the other
 * admin-only hookables.
 *
 * The constructor signature is `(string $version)` per src/Plugin.php.
 */

/**
 * Plugin test case
 *
 * Tests the main Plugin orchestrator class.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Plugin
 */
class PluginTest extends TestCase {

	/**
	 * Plugin instance
	 *
	 * @var ArchivedPostStatus\Plugin
	 */
	protected $plugin;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->plugin = new ArchivedPostStatus\Plugin( '0.4.0' );
	}

	// two prior `test_plugin_construction*` tests were deleted.
	// Asserting `instanceof Plugin` against a newly-constructed Plugin
	// is tautological — PHP guarantees `new X()` yields an X. The version
	// argument is exercised end-to-end via the `run()` tests below, which
	// verify the constructed version flows through upgrade_check() into
	// the option store.

	// -----------------------------------------------------------------------
	// hookables() composition root
	// -----------------------------------------------------------------------
	//
	// The hookables() method is the single place that determines which
	// classes WordPress actually sees from this plugin. A typo in that
	// array silently drops a feature; the surface is private so it has
	// to be reached via reflection (same pattern LoaderTest uses for the
	// Loader constructor).
	//
	// The expectations below mirror src/Plugin.php exactly — the goal of
	// these tests is to lock the composition so that adding/removing a
	// line in hookables() forces a test update at the same time.

	/**
	 * Invoke the private Plugin::hookables() method via reflection.
	 *
	 * @return ArchivedPostStatus\Contracts\HookableInterface[]
	 */
	private function invoke_hookables(): array {
		$method = new ReflectionMethod( ArchivedPostStatus\Plugin::class, 'hookables' );
		$method->setAccessible( true );
		return $method->invoke( $this->plugin );
	}

	/**
	 * Extract the FQCN list from a hookables array so assertions can
	 * compare against a string list rather than a heterogeneous object
	 * graph.
	 *
	 * @param object[] $hookables Hookable instances.
	 * @return string[] Fully-qualified class names.
	 */
	private function class_names( array $hookables ): array {
		return array_map( static fn( $h ) => $h::class, $hookables );
	}

	/**
	 * The unconditional spine of the plugin: regardless of admin context
	 * or WP-CLI, these eight hookables are always wired. If one disappears
	 * silently, an important hook stops registering and no other test
	 * catches it.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_always_registers_core_status_frontend_and_settings_hookables() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertContains( ArchivedPostStatus\Status\PostStatus::class, $names );
		$this->assertContains( ArchivedPostStatus\Status\PostStatusGuard::class, $names );
		$this->assertContains( ArchivedPostStatus\Frontend\ArchiveTitle::class, $names );
		$this->assertContains( ArchivedPostStatus\Frontend\AccessGuard::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\PostEditor::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\PostEditorGuard::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\Notices::class, $names );
		$this->assertContains( ArchivedPostStatus\Settings\HookAdapter::class, $names );
	}

	/**
	 * `Notices` is constructed with a `NoticeBuilder` injected via the
	 * constructor — `Plugin::hookables()` is the single composition root
	 * that does the `new`. If anyone tries to `new Notices()` (zero-arg)
	 * the call would now fail at the language level; this test pins the
	 * positive shape: the builder property exists and is the right type.
	 *
	 * Reflection is required because `Notices::$builder` is private
	 * readonly — there's no public accessor, and exposing one solely to
	 * satisfy this assertion would weaken encapsulation. Same pattern as
	 * the other private-property pins in this file.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_constructs_notices_with_notice_builder_dependency() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$hookables = $this->invoke_hookables();

		$notices = null;
		foreach ( $hookables as $hookable ) {
			if ( $hookable instanceof ArchivedPostStatus\Admin\Notices ) {
				$notices = $hookable;
				break;
			}
		}

		$this->assertNotNull( $notices, 'Notices must appear in hookables()' );

		$property = new ReflectionProperty( ArchivedPostStatus\Admin\Notices::class, 'builder' );
		$property->setAccessible( true );

		$this->assertInstanceOf(
			ArchivedPostStatus\Admin\NoticeBuilder::class,
			$property->getValue( $notices ),
			'Notices must be constructed with a NoticeBuilder dependency'
		);
	}

	/**
	 * The `aps_enable_archive_meta` filter has a default of `true`, so
	 * `ArchiveMetaListener` is part of the default composition. Removing
	 * it means archives stop recording metadata — a regression we want
	 * loudly visible.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_includes_archive_meta_listener_when_feature_filter_returns_true() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertContains( ArchivedPostStatus\Archive\ArchiveMetaListener::class, $names );
	}

	/**
	 * When a site filters `aps_enable_archive_meta` to false (the documented
	 * opt-out for sites that don't want the extra postmeta rows), the
	 * listener must be omitted entirely — not registered with a no-op
	 * guard, not registered conditionally inside its own hooks.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_omits_archive_meta_listener_when_filter_returns_false() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertNotContains( ArchivedPostStatus\Archive\ArchiveMetaListener::class, $names );
	}

	/**
	 * The admin-only set — PostList (post list table), ArchiveColumn (the
	 * archive metadata column), and PluginScreen (the deactivation warning)
	 * — only matter on admin page loads. Gating them via is_admin() avoids
	 * hooking front-end queries.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_includes_admin_only_set_when_is_admin_is_true() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertContains( ArchivedPostStatus\Admin\PostList::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\ArchiveColumn::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\PluginScreen::class, $names );
	}

	/**
	 * `PostList` is constructed with a `BulkActionHandler` injected via the
	 * constructor — `Plugin::hookables()` is the single composition root
	 * that does the `new`. If anyone tries to `new PostList()` (zero-arg)
	 * the call would now fail at the language level; this test pins the
	 * positive shape: the bulk_handler property exists and is the right type.
	 *
	 * Same reflection pattern as the Notices and CLI composition tests
	 * elsewhere in this file — private readonly property, no public
	 * accessor, reflection is the surgical pin.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_constructs_post_list_with_bulk_action_handler_dependency() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$hookables = $this->invoke_hookables();

		$post_list = null;
		foreach ( $hookables as $hookable ) {
			if ( $hookable instanceof ArchivedPostStatus\Admin\PostList ) {
				$post_list = $hookable;
				break;
			}
		}

		$this->assertNotNull( $post_list, 'PostList must appear in hookables() when is_admin is true' );

		$property = new ReflectionProperty( ArchivedPostStatus\Admin\PostList::class, 'bulk_handler' );
		$property->setAccessible( true );

		$this->assertInstanceOf(
			ArchivedPostStatus\Admin\BulkActionHandler::class,
			$property->getValue( $post_list ),
			'PostList must be constructed with a BulkActionHandler dependency'
		);
	}

	/**
	 * Mirror: under a non-admin request (front-end page view, REST API
	 * call, etc.), neither PostList nor ArchiveColumn should appear.
	 * Their hooks fire on `admin_*` events which would never reach this
	 * code path, but composing them anyway burns autoloader cycles and
	 * makes the dependency graph less honest.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_omits_admin_only_set_when_is_admin_is_false() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertNotContains( ArchivedPostStatus\Admin\PostList::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\ArchiveColumn::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\PluginScreen::class, $names );
	}

	/**
	 * The WP-CLI hookable registers the `wp aps archive` / `unarchive`
	 * commands. It must only appear when WP_CLI is defined and truthy —
	 * otherwise add_command() would call into an undefined class.
	 *
	 * Using `@runInSeparateProcess` because `define()` of WP_CLI persists
	 * across tests once set, and a leaked definition would silently make
	 * sibling tests' expectations pass for the wrong reason.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hookables_includes_cli_hookable_when_wp_cli_is_defined() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$method = new ReflectionMethod( ArchivedPostStatus\Plugin::class, 'hookables' );
		$method->setAccessible( true );
		$hookables = $method->invoke( new ArchivedPostStatus\Plugin( '0.4.0' ) );
		$names     = array_map( static fn( $h ) => $h::class, $hookables );

		$this->assertContains( ArchivedPostStatus\CLI\Registrar::class, $names );
	}

	/**
	 * `CLI` is constructed with `CommandRunner`, `ArchiveCommand`, and
	 * `UnarchiveCommand` injected via the constructor — `Plugin::hookables()`
	 * is the single composition root. If the CLI is ever instantiated
	 * zero-arg the language itself will throw; this test pins the positive
	 * shape that each injected collaborator has the right type. Same
	 * reflection pattern as the Notices composition test above.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_hookables_constructs_cli_with_runner_and_command_dependencies() {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$method = new ReflectionMethod( ArchivedPostStatus\Plugin::class, 'hookables' );
		$method->setAccessible( true );
		$hookables = $method->invoke( new ArchivedPostStatus\Plugin( '0.4.0' ) );

		$cli = null;
		foreach ( $hookables as $hookable ) {
			if ( $hookable instanceof ArchivedPostStatus\CLI\Registrar ) {
				$cli = $hookable;
				break;
			}
		}

		$this->assertNotNull( $cli, 'CLI must appear in hookables() when WP_CLI is defined' );

		$runner_prop = new ReflectionProperty( ArchivedPostStatus\CLI\Registrar::class, 'runner' );
		$runner_prop->setAccessible( true );
		$this->assertInstanceOf(
			ArchivedPostStatus\CLI\CommandRunner::class,
			$runner_prop->getValue( $cli ),
			'CLI must be constructed with a CommandRunner dependency'
		);

		$archive_prop = new ReflectionProperty( ArchivedPostStatus\CLI\Registrar::class, 'archive_command' );
		$archive_prop->setAccessible( true );
		$this->assertInstanceOf(
			ArchivedPostStatus\CLI\ArchiveCommand::class,
			$archive_prop->getValue( $cli ),
			'CLI must be constructed with an ArchiveCommand dependency'
		);

		$unarchive_prop = new ReflectionProperty( ArchivedPostStatus\CLI\Registrar::class, 'unarchive_command' );
		$unarchive_prop->setAccessible( true );
		$this->assertInstanceOf(
			ArchivedPostStatus\CLI\UnarchiveCommand::class,
			$unarchive_prop->getValue( $cli ),
			'CLI must be constructed with an UnarchiveCommand dependency'
		);
	}

	// -----------------------------------------------------------------------
	// run() + upgrade_check() + load_textdomain()
	// -----------------------------------------------------------------------
	//
	// run() is the bootstrap entry point invoked by aps_run_plugin() on
	// the plugins_loaded hook. It fires the public aps_init / aps_loaded
	// actions and instantiates the HookLoader. The supporting private
	// methods upgrade_check() (option round-trip) and load_textdomain()
	// (i18n) are exercised through run().

	/**
	 * run() emits the documented aps_init / aps_loaded boundary actions
	 * exactly once each per call. Plugins that hook these (e.g. add-on
	 * post-type registration) rely on them firing.
	 *
	 * @covers ArchivedPostStatus\Plugin::run
	 * @covers ArchivedPostStatus\Plugin::upgrade_check
	 * @covers ArchivedPostStatus\Plugin::load_textdomain
	 */
	public function test_run_fires_aps_init_and_aps_loaded_actions() {
		\WP_Mock::expectAction( 'aps_init' );
		\WP_Mock::expectAction( 'aps_loaded' );

		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'archived_post_status_version', false )
			->andReturn( '0.4.0' ); // same as $version → no update_option

		\WP_Mock::userFunction( 'load_plugin_textdomain' )
			->once()
			->with( 'archived-post-status', false, \WP_Mock\Functions::type( 'string' ) );

		// Stub hookables so HookLoader has something safe to iterate.
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );

		$this->plugin->run();

		// WP_Mock verifies expected actions/userFunctions on tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * upgrade_check() persists the new version when the stored version
	 * differs from the current one, and records the previous version in
	 * `archived_post_status_previous_version`. This is the upgrade-
	 * tracking contract the 0.4.0 release introduced.
	 *
	 * Atomicity invariant: both writes belong to the same logical
	 * transition. add_option is NOT called in the upgrade branch (the
	 * option exists by definition).
	 *
	 * @covers ArchivedPostStatus\Plugin::run
	 * @covers ArchivedPostStatus\Plugin::upgrade_check
	 */
	public function test_upgrade_path_writes_the_options_atomically() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'archived_post_status_version', false )
			->andReturn( '0.3.9' ); // older version stored

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'archived_post_status_previous_version', '0.3.9', false );
		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'archived_post_status_version', '0.4.0', false );
		// add_option is reserved for the first-install branch; the upgrade
		// path must not touch it (the option exists by definition).
		\WP_Mock::userFunction( 'add_option' )->never();

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->once();
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );

		$this->plugin->run();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * On a fresh install (no version option recorded yet, no archived
	 * content in the database), upgrade_check() writes the current version
	 * via `add_option` (race-safe creator: a concurrent request that
	 * already created the option is not clobbered) and does NOT touch the
	 * previous-version option — there is no previous to record.
	 *
	 * @covers ArchivedPostStatus\Plugin::run
	 * @covers ArchivedPostStatus\Plugin::upgrade_check
	 */
	public function test_first_install_writes_the_options_atomically() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'archived_post_status_version', false )
			->andReturn( false ); // no stored version

		$GLOBALS['wpdb'] = new class() {
			public $posts = 'wp_posts';
			public function get_var( $query ) {
				return null; // No archived content: a true fresh install.
			}
		};

		\WP_Mock::userFunction( 'add_option' )
			->with( 'archived_post_status_version', '0.4.0', '', false )
			->once();
		// Neither update_option write should fire on a fresh install — the
		// version is created via add_option and there is no previous version
		// to record.
		\WP_Mock::userFunction( 'update_option' )
			->with( 'archived_post_status_version', \WP_Mock\Functions::type( 'string' ), false )
			->never();
		\WP_Mock::userFunction( 'update_option' )
			->with( 'archived_post_status_previous_version', \WP_Mock\Functions::type( 'string' ), false )
			->never();

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->once();
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );

		try {
			$this->plugin->run();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A 0.3.x site upgrading to 0.4.0 has no version option either — the
	 * pre-0.4.0 releases never wrote one. Archived content (rows holding
	 * the literal 'archive' status) is the distinguishing evidence, and
	 * upgrade_check() records the 'pre-0.4.0' marker so future migrations
	 * can tell this site apart from a fresh install.
	 *
	 * @covers ArchivedPostStatus\Plugin::run
	 * @covers ArchivedPostStatus\Plugin::upgrade_check
	 */
	public function test_pre_040_upgrade_records_the_previous_version_marker() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'archived_post_status_version', false )
			->andReturn( false ); // 0.3.x never wrote the option.

		$probed_query        = null;
		$GLOBALS['wpdb'] = new class() {
			public $posts = 'wp_posts';
			public $last_query;
			public function get_var( $query ) {
				$this->last_query = $query;
				return '42'; // An archived post exists.
			}
		};

		\WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'archived_post_status_previous_version', 'pre-0.4.0', false );
		\WP_Mock::userFunction( 'add_option' )
			->with( 'archived_post_status_version', '0.4.0', '', false )
			->once();

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->once();
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );

		try {
			$this->plugin->run();
			// The probe targets the literal legacy status string, not the
			// filterable slug — pre-0.4.0 writes hardcoded 'archive'.
			$this->assertStringContainsString(
				"post_status = 'archive'",
				$GLOBALS['wpdb']->last_query
			);
		} finally {
			unset( $GLOBALS['wpdb'] );
		}
	}

	/**
	 * Steady state: the stored version already matches the current one.
	 * This is the branch every admin request hits on an already-upgraded
	 * site, i.e. nearly every admin page load in the wild. get_option()
	 * legitimately fires — the branch has to read the stored version to
	 * discover there is nothing to do — but neither write function may
	 * fire. A regression here means a database write on every admin
	 * request instead of none.
	 *
	 * @covers ArchivedPostStatus\Plugin::run
	 * @covers ArchivedPostStatus\Plugin::upgrade_check
	 */
	public function test_upgrade_check_writes_nothing_when_stored_version_matches_current() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'archived_post_status_version', false )
			->andReturn( '0.4.0' ); // steady state: stored === current.

		// Defensive: if this ever regressed into the first-install branch,
		// has_pre_040_content() would dereference $wpdb. Stubbing it means
		// such a regression fails on the update_option/add_option
		// assertions below instead of a null-pointer crash.
		$GLOBALS['wpdb'] = new class() {
			public $posts = 'wp_posts';
			public function get_var( $query ) {
				return null;
			}
		};

		// This is the no-op branch — neither write function may fire.
		\WP_Mock::userFunction( 'update_option' )->never();
		\WP_Mock::userFunction( 'add_option' )->never();

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->once();
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );

		try {
			$this->plugin->run();
		} finally {
			unset( $GLOBALS['wpdb'] );
		}

		$this->addToAssertionCount( 1 );
	}

	/**
	 * upgrade_check() is admin-only — it's a "while we're rendering an
	 * admin page anyway" hook. Front-end requests should bypass option
	 * writes entirely to avoid the autoloaded-options cost on every
	 * pageview.
	 *
	 * @covers ArchivedPostStatus\Plugin::run
	 * @covers ArchivedPostStatus\Plugin::upgrade_check
	 */
	public function test_upgrade_check_is_skipped_on_non_admin_requests() {
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		// No option I/O should happen on the front-end path.
		\WP_Mock::userFunction( 'get_option' )->never();
		\WP_Mock::userFunction( 'update_option' )->never();
		\WP_Mock::userFunction( 'add_option' )->never();

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->once();
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( false );

		$this->plugin->run();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Mirror: with WP_CLI undefined (the normal HTTP request case), the
	 * CLI hookable must not be wired. WP_CLI is undefined in the default
	 * test bootstrap so this scenario runs in-process without isolation.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_omits_cli_hookable_when_wp_cli_is_not_defined() {
		// Sanity-pin: the standard test bootstrap doesn't define WP_CLI,
		// so this is exercising the realistic non-CLI request path.
		$this->assertFalse(
			defined( 'WP_CLI' ),
			'WP_CLI must not be defined for this scenario'
		);

		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertNotContains( ArchivedPostStatus\CLI\Registrar::class, $names );
	}
}
