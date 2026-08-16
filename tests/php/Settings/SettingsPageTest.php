<?php
/**
 * Settings\SettingsPage Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\SettingsPage
 *
 * Per the phase brief's non-negotiable list: the menu is not added when the
 * capability is denied; register_setting is called with the Sanitizer as
 * sanitize_callback and with show_in_rest present; the cron-health notice
 * appears when aps_last_sweep is stale and not when it is fresh.
 */

use ArchivedPostStatus\Settings\Sanitizer;
use ArchivedPostStatus\Settings\SettingsPage;
use ArchivedPostStatus\Settings\Store;
use ArchivedPostStatus\Tests\Support\BoundaryStubs;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\SettingsPage
 */
class SettingsPageTest extends TestCase {

	use BoundaryStubs;

	/**
	 * @var ArchivedPostStatus\Settings\SettingsPage
	 */
	protected $page;

	public function set_up() {
		parent::set_up();
		Store::flush_cache();
		$this->page = new SettingsPage();
	}

	public function tear_down() {
		Store::flush_cache();
		parent::tear_down();
	}

	/**
	 * Stubs the full chain `render_page()` walks: capability, the cron
	 * option, the Settings API form scaffolding, and every field's own
	 * choice-list boundary.
	 *
	 * @param bool $capability_granted
	 * @param int  $last_sweep
	 */
	private function stubFullRenderBoundary( bool $capability_granted, int $last_sweep ): void {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( $capability_granted );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'aps_last_sweep', 0 )
			->andReturn( $last_sweep );
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array() );
		\WP_Mock::userFunction( 'settings_fields' )->once();
		\WP_Mock::userFunction( 'submit_button' )->once();
		$category                = new stdClass();
		$category->name          = 'category';
		$category->labels        = new stdClass();
		$category->labels->name  = 'Categories';
		\WP_Mock::userFunction( 'get_taxonomies' )
			->with( array(), 'objects' )
			->andReturn( array( $category ) );
		$this->stubSupportedPostTypesBoundary();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Settings\SettingsPage::hooks
	 */
	public function test_hooks_registers_admin_menu_admin_init_and_option_page_capability_filter() {
		$hooks = $this->page->hooks();

		$this->assertCount( 3, $hooks );
		$this->assertSame( 'admin_menu', $hooks[0]->hook );
		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'admin_init', $hooks[1]->hook );
		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'option_page_capability_aps', $hooks[2]->hook );
		$this->assertSame( 'filter', $hooks[2]->type );
	}

	// -----------------------------------------------------------------------
	// add_menu_page()
	// -----------------------------------------------------------------------

	/**
	 * The menu is not added at all when the capability is denied — not
	 * added-then-hidden.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::add_menu_page
	 */
	public function test_add_menu_page_does_not_add_page_when_capability_denied() {
		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );
		\WP_Mock::userFunction( 'add_options_page' )->never();

		$this->page->add_menu_page();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * With the capability granted, the page is added under Settings with
	 * the resolved capability string.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::add_menu_page
	 */
	public function test_add_menu_page_adds_page_when_capability_granted() {
		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( true );

		$captured = null;
		\WP_Mock::userFunction( 'add_options_page' )
			->once()
			->andReturnUsing(
				function ( $page_title, $menu_title, $capability, $menu_slug, $callback ) use ( &$captured ) {
					$captured = compact( 'page_title', 'menu_title', 'capability', 'menu_slug', 'callback' );
				}
			);

		$this->page->add_menu_page();

		$this->assertSame( 'manage_options', $captured['capability'] );
		$this->assertSame( SettingsPage::PAGE_SLUG, $captured['menu_slug'] );
		$this->assertSame( array( $this->page, 'render_page' ), $captured['callback'] );
	}

	/**
	 * The capability passed to `add_options_page()` is the *resolved*
	 * `aps_default_settings_capability` value, not a hardcoded
	 * `'manage_options'` string that only happens to match the default — a
	 * site overriding the filter must see that override on the menu item
	 * too, not just on the form's POST handler ({@see option_page_capability()}).
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::add_menu_page
	 */
	public function test_add_menu_page_uses_the_filtered_capability_not_a_hardcoded_default() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::onFilter( 'aps_default_settings_capability' )
			->with( 'manage_options' )
			->reply( 'manage_network_options' );

		$captured = null;
		\WP_Mock::userFunction( 'add_options_page' )
			->once()
			->andReturnUsing(
				function ( $page_title, $menu_title, $capability, $menu_slug, $callback ) use ( &$captured ) {
					$captured = compact( 'capability' );
				}
			);

		$this->page->add_menu_page();

		$this->assertSame( 'manage_network_options', $captured['capability'] );
	}

	// -----------------------------------------------------------------------
	// register_setting()
	// -----------------------------------------------------------------------

	/**
	 * register_setting() is called with the Sanitizer as sanitize_callback
	 * and with show_in_rest present — the entire REST requirement for site
	 * settings (§5.8).
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::register_setting
	 */
	public function test_register_setting_registers_with_sanitizer_and_show_in_rest() {
		$captured = null;
		\WP_Mock::userFunction( 'register_setting' )
			->once()
			->andReturnUsing(
				function ( $option_group, $option_name, $args ) use ( &$captured ) {
					$captured = compact( 'option_group', 'option_name', 'args' );
				}
			);

		$this->page->register_setting();

		$this->assertSame( 'aps', $captured['option_group'] );
		$this->assertSame( Store::OPTION_KEY, $captured['option_name'] );
		$this->assertSame( 'object', $captured['args']['type'] );
		$this->assertSame( array( Sanitizer::class, 'sanitize' ), $captured['args']['sanitize_callback'] );
		$this->assertSame( Store::defaults(), $captured['args']['default'] );
		$this->assertArrayHasKey( 'show_in_rest', $captured['args'] );
		$this->assertArrayHasKey( 'schema', $captured['args']['show_in_rest'] );
	}

	// -----------------------------------------------------------------------
	// option_page_capability()
	// -----------------------------------------------------------------------

	/**
	 * The filter returns the resolved settings capability, replacing
	 * whatever default options.php passed in.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::option_page_capability
	 */
	public function test_option_page_capability_returns_the_resolved_settings_capability() {
		\WP_Mock::onFilter( 'aps_default_settings_capability' )
			->with( 'manage_options' )
			->reply( 'manage_network_options' );

		$this->assertSame( 'manage_network_options', $this->page->option_page_capability( 'manage_options' ) );
	}

	// -----------------------------------------------------------------------
	// render_page()
	// -----------------------------------------------------------------------

	/**
	 * Nothing renders at all when the capability is denied.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_renders_nothing_when_capability_denied() {
		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The cron-health notice appears when `aps_last_sweep` is stale (here,
	 * far beyond the 3x-interval threshold).
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_shows_cron_notice_when_sweep_is_stale() {
		$this->stubFullRenderBoundary( true, time() - 10000 );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/**
	 * The cron-health notice does not appear when `aps_last_sweep` is
	 * recent.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_omits_cron_notice_when_sweep_is_fresh() {
		$this->stubFullRenderBoundary( true, time() - 10 );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-warning', $output );
	}

	/**
	 * `aps_schedule_stale_multiplier` changes the staleness threshold: an
	 * elapsed time past 1x the interval but still under the default 3x does
	 * not warn at the default multiplier, but does once a site filters it
	 * down to 1.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_honors_a_filtered_stale_multiplier() {
		$this->stubFullRenderBoundary( true, time() - 400 );
		\WP_Mock::onFilter( 'aps_schedule_stale_multiplier' )
			->with( \ArchivedPostStatus\Settings\CronHealthCheck::DEFAULT_STALE_MULTIPLIER )
			->reply( 1 );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-warning', $output );
	}

	/**
	 * A never-swept site with real cron available (DISABLE_WP_CRON not
	 * defined truthy in the test runtime) does not warn — it has not had
	 * the chance to fail yet.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_omits_cron_notice_when_never_swept_and_cron_not_disabled() {
		$this->stubFullRenderBoundary( true, 0 );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-warning', $output );
	}

	/**
	 * The rendered page includes the settings form scaffolding (nonce via
	 * settings_fields(), the cascade fieldset for auto_archive_days) and
	 * folds auto_archive_child_mode into that same fieldset rather than
	 * rendering it as a separate field row.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_renders_the_cascade_field_and_folds_in_child_mode() {
		$this->stubFullRenderBoundary( true, time() );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( '<form method="post" action="options.php">', $output );
		$this->assertStringContainsString( 'name="auto_archive_days"', $output );
		$this->assertStringContainsString( 'name="auto_archive_child_mode"', $output );
		$this->assertStringContainsString( 'aps-cascade-downstream', $output );
	}

	/**
	 * A stored `auto_archive_child_mode` value ChildMode::tryFrom() cannot
	 * resolve (corrupt/stale option data, e.g. from a downgrade) falls back
	 * to Open rather than fataling on a null enum.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_cascade_field
	 */
	public function test_render_page_falls_back_to_open_for_an_unrecognized_stored_child_mode() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'get_option' )
			->with( 'aps_last_sweep', 0 )
			->andReturn( time() );
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'auto_archive_child_mode' => 'not-a-real-mode' ) );
		\WP_Mock::userFunction( 'settings_fields' )->once();
		\WP_Mock::userFunction( 'submit_button' )->once();
		\WP_Mock::userFunction( 'get_taxonomies' )->with( array(), 'objects' )->andReturn( array() );
		$this->stubSupportedPostTypesBoundary();

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="open" checked="checked"/', $output );
	}

	/**
	 * Every non-cascade field renders too — spot-checked via each field's
	 * `name` attribute, one per Schema key other than the cascade pair.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsPage::render_page
	 */
	public function test_render_page_renders_every_non_cascade_field() {
		$this->stubFullRenderBoundary( true, time() );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		foreach ( array( 'is_read_only', 'scheduled_archive_enabled', 'scheduled_archive_post_types', 'auto_archive_enabled', 'auto_archive_types', 'auto_archive_taxonomies', 'auto_archive_age_basis', 'auto_archive_grace_days' ) as $key ) {
			$this->assertStringContainsString( "name=\"{$key}", $output, "field for '{$key}'" );
		}
	}
}
