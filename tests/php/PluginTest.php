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
	 * or WP-CLI, these seven hookables are always wired. If one disappears
	 * silently, an important hook stops registering and no other test
	 * catches it.
	 *
	 * `PostEditor` and `Notices` are deliberately NOT asserted here — every
	 * hook they register only fires on an actual wp-admin page load, so
	 * they live in the `is_admin()`-gated admin-only set instead; see
	 * {@see test_hookables_includes_admin_only_set_when_is_admin_is_true()}
	 * and {@see test_hookables_omits_admin_only_set_when_is_admin_is_false()}.
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
		$this->assertContains( ArchivedPostStatus\Admin\PostEditorGuard::class, $names );
		$this->assertContains( ArchivedPostStatus\Settings\HookAdapter::class, $names );
		$this->assertContains( ArchivedPostStatus\Settings\TermMetaRegistrar::class, $names );
	}

	/**
	 * The schedule/cron wiring -- both CronQueueRunner instances (sweep and
	 * stamp), cron self-repair, per-post-type meta registration, and the
	 * archived-post schedule cleanup listener -- fires on cron ticks and
	 * REST requests, neither of which is `is_admin()`. `is_admin()` is
	 * stubbed `false` here specifically: `PluginHookablesParityTest` always
	 * stubs it `true`, so that snapshot alone cannot see these hookables
	 * being gated behind `is_admin()` by mistake -- a regression that would
	 * silently stop the sweeper (or the stamper) from ever running outside
	 * wp-admin while every existing test stayed green.
	 *
	 * Asserts a COUNT of 2 for `CronQueueRunner`, not merely presence --
	 * `assertContains` alone cannot distinguish "both runners registered"
	 * from "only one did, and this test happens to be checking for the
	 * class both would share".
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 * @covers ArchivedPostStatus\Plugin::schedule_hookables
	 */
	public function test_hookables_registers_schedule_and_cron_wiring_when_is_admin_is_false() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertSame(
			2,
			count( array_filter( $names, static fn( $name ) => ArchivedPostStatus\Schedule\Queue\CronQueueRunner::class === $name ) ),
			'Both the sweep and stamp CronQueueRunner instances must be registered.'
		);
		$this->assertContains( ArchivedPostStatus\Schedule\CronRegistrar::class, $names );
		$this->assertContains( ArchivedPostStatus\Schedule\MetaRegistrar::class, $names );
		$this->assertContains( ArchivedPostStatus\Schedule\ScheduleMetaListener::class, $names );
	}

	/**
	 * The second `CronQueueRunner` instance wraps a `RuleStamper`
	 * constructed over `RuleChain::default()` -- the canonical four-level
	 * cascade, general to specific per the resolver's own ordering
	 * requirement -- `NetworkRuleProvider`, `SiteRuleProvider`,
	 * `TermRuleProvider`, then `PostRuleProvider`, the full cascade this
	 * release ships (0.5.0 phase 9). Reflection is required because both
	 * `CronQueueRunner::$processor` and `RuleStamper::$chain` are private
	 * readonly properties with no public accessor, and `RuleChain::$providers`
	 * likewise -- same pattern as the other private-property pins in this file.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 * @covers ArchivedPostStatus\Plugin::schedule_hookables
	 */
	public function test_hookables_wires_the_stamp_runner_with_a_rule_stamper_over_a_network_then_site_then_term_then_post_rule_provider_chain() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$hookables = $this->invoke_hookables();

		$runners = array_values(
			array_filter( $hookables, static fn( $h ) => $h instanceof ArchivedPostStatus\Schedule\Queue\CronQueueRunner )
		);
		$this->assertCount( 2, $runners );

		$processor_property = new ReflectionProperty( ArchivedPostStatus\Schedule\Queue\CronQueueRunner::class, 'processor' );
		$processor_property->setAccessible( true );

		$stamper = null;
		foreach ( $runners as $runner ) {
			$processor = $processor_property->getValue( $runner );
			if ( $processor instanceof ArchivedPostStatus\AutoArchive\RuleStamper ) {
				$stamper = $processor;
			}
		}

		$this->assertInstanceOf( ArchivedPostStatus\AutoArchive\RuleStamper::class, $stamper, 'One CronQueueRunner must wrap a RuleStamper.' );

		$chain_property = new ReflectionProperty( ArchivedPostStatus\AutoArchive\RuleStamper::class, 'chain' );
		$chain_property->setAccessible( true );
		$chain = $chain_property->getValue( $stamper );

		$this->assertInstanceOf( ArchivedPostStatus\AutoArchive\RuleChain::class, $chain );

		$providers_property = new ReflectionProperty( ArchivedPostStatus\AutoArchive\RuleChain::class, 'providers' );
		$providers_property->setAccessible( true );
		$providers = $providers_property->getValue( $chain );

		$this->assertCount( 4, $providers );
		$this->assertInstanceOf( ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider::class, $providers[0], 'The network level must be first -- general to specific.' );
		$this->assertInstanceOf( ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider::class, $providers[1] );
		$this->assertInstanceOf( ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider::class, $providers[2] );
		$this->assertInstanceOf( ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider::class, $providers[3], 'The post level must be last -- most specific of the four.' );
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
	 * `is_admin()` must be true here — `Notices` only appears in the
	 * admin-only set (see {@see \ArchivedPostStatus\Plugin::hookables()}).
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_constructs_notices_with_notice_builder_dependency() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

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
	 * The admin-only set — PostEditor (editor assets + classic-editor
	 * button), Notices (admin_notices), PostList (post list table),
	 * PostActionHandler (the single-post archive/unarchive admin actions),
	 * ArchiveColumn (the archive metadata column), ArchiveColumnSort (the
	 * column's meta-aware sorting), ScheduleColumn (the Scheduled column,
	 * 0.5.0 phase 10), ScheduleColumnSort (its meta-aware sorting), and
	 * PluginScreen (the deactivation warning) — only matter on admin page
	 * loads. Gating them via is_admin() avoids hooking front-end/CLI
	 * requests with admin-only hooks (admin_enqueue_scripts,
	 * post_submitbox_start, admin_notices, etc.) that would never fire there
	 * anyway.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_includes_admin_only_set_when_is_admin_is_true() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertContains( ArchivedPostStatus\Admin\PostEditor::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\Notices::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\PostList::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\PostActionHandler::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\ArchiveColumn::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\ArchiveColumnSort::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\ScheduleColumn::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\ScheduleColumnSort::class, $names );
		$this->assertContains( ArchivedPostStatus\Admin\PluginScreen::class, $names );
		$this->assertContains( ArchivedPostStatus\Settings\SettingsPage::class, $names );
		$this->assertContains( ArchivedPostStatus\Settings\TermFields::class, $names );
	}

	/**
	 * `NetworkSettingsPage` is registered ONLY when the plugin is
	 * network-activated ({@see ArchivedPostStatus\Settings\NetworkActivation}),
	 * even under `is_admin() === true` -- mirrors the CLI `Registrar`'s own
	 * conditional presence. `is_multisite()` defaults false in the test
	 * runtime (see `WpPolyfills.php`), so this is the ordinary case every
	 * other admin-only-set test above already exercises without knowing it.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_omits_network_settings_page_when_not_network_activated() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'get_site_option' )->never();

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertNotContains( ArchivedPostStatus\Settings\NetworkSettingsPage::class, $names );
	}

	/**
	 * Multisite, but this plugin is not active at the network level:
	 * `NetworkSettingsPage` is still omitted.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_omits_network_settings_page_when_multisite_but_not_network_activated() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array() );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertNotContains( ArchivedPostStatus\Settings\NetworkSettingsPage::class, $names );
	}

	/**
	 * Network-activated: `NetworkSettingsPage` IS registered, alongside
	 * (not instead of) the site `SettingsPage` -- a network-activated
	 * install still has a site screen too.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_includes_network_settings_page_when_network_activated() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertContains( ArchivedPostStatus\Settings\NetworkSettingsPage::class, $names );
		$this->assertContains( ArchivedPostStatus\Settings\SettingsPage::class, $names );
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
	 * `PostActionHandler` needs `BulkActionHandler::get_redirect_url()` for
	 * its own redirect composition, so `Plugin::hookables()` must inject the
	 * SAME `BulkActionHandler` instance into both `PostList` and
	 * `PostActionHandler` — not merely an equivalent one. `assertSame`
	 * (identity), not `assertInstanceOf` (type), is the only assertion that
	 * actually pins that invariant; two separately-`new`'d instances would
	 * pass an `assertInstanceOf`-only check while still being observably
	 * different objects.
	 *
	 * Same reflection pattern as
	 * {@see test_hookables_constructs_post_list_with_bulk_action_handler_dependency()}
	 * above, applied to both hookables' private readonly properties.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_construct_post_list_and_post_action_handler_with_the_same_bulk_action_handler_instance() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$hookables = $this->invoke_hookables();

		$post_list           = null;
		$post_action_handler = null;
		foreach ( $hookables as $hookable ) {
			if ( $hookable instanceof ArchivedPostStatus\Admin\PostList ) {
				$post_list = $hookable;
			}
			if ( $hookable instanceof ArchivedPostStatus\Admin\PostActionHandler ) {
				$post_action_handler = $hookable;
			}
		}

		$this->assertNotNull( $post_list, 'PostList must appear in hookables() when is_admin is true' );
		$this->assertNotNull( $post_action_handler, 'PostActionHandler must appear in hookables() when is_admin is true' );

		$post_list_property = new ReflectionProperty( ArchivedPostStatus\Admin\PostList::class, 'bulk_handler' );
		$post_list_property->setAccessible( true );

		$post_action_handler_property = new ReflectionProperty( ArchivedPostStatus\Admin\PostActionHandler::class, 'bulk_handler' );
		$post_action_handler_property->setAccessible( true );

		$this->assertSame(
			$post_list_property->getValue( $post_list ),
			$post_action_handler_property->getValue( $post_action_handler ),
			'PostList and PostActionHandler must share the exact same BulkActionHandler instance'
		);
	}

	/**
	 * Mirror: under a non-admin request (front-end page view, REST API
	 * call, etc.), none of PostEditor, Notices, PostList,
	 * PostActionHandler, ArchiveColumn, ArchiveColumnSort, ScheduleColumn,
	 * ScheduleColumnSort, or PluginScreen should appear. Every hook they
	 * register only fires on an actual wp-admin page load, so composing them
	 * anyway burns autoloader cycles and makes the dependency graph less
	 * honest.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_omits_admin_only_set_when_is_admin_is_false() {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( false );

		$names = $this->class_names( $this->invoke_hookables() );

		$this->assertNotContains( ArchivedPostStatus\Admin\PostEditor::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\Notices::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\PostList::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\PostActionHandler::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\ArchiveColumn::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\ArchiveColumnSort::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\ScheduleColumn::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\ScheduleColumnSort::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Admin\PluginScreen::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Settings\SettingsPage::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Settings\TermFields::class, $names );
		$this->assertNotContains( ArchivedPostStatus\Settings\NetworkSettingsPage::class, $names );
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
	 */
	public function test_run_fires_aps_init_and_aps_loaded_actions() {
		\WP_Mock::expectAction( 'aps_init' );
		\WP_Mock::expectAction( 'aps_loaded' );

		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'archived_post_status_version', false )
			->andReturn( '0.4.0' ); // same as $version → no update_option

		// Translations load just in time from the Domain Path header; the
		// plugin must not call this itself, which on plugins_loaded would
		// trip WP 6.7+'s "triggered too early" notice.
		\WP_Mock::userFunction( 'load_plugin_textdomain' )->never();

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

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->never();
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

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->never();
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

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->never();
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

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->never();
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

		\WP_Mock::userFunction( 'load_plugin_textdomain' )->never();
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
