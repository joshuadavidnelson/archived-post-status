<?php
/**
 * Settings\NetworkSettingsPage Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage
 *
 * Per the phase brief's non-negotiable list: no menu without the capability;
 * the save handler rejects a bad nonce; the save handler rejects an
 * insufficient capability; a valid POST writes sanitized values.
 */

use ArchivedPostStatus\Settings\NetworkSettingsPage;
use ArchivedPostStatus\Settings\NetworkStore;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage
 */
class NetworkSettingsPageTest extends TestCase {

	/**
	 * @var ArchivedPostStatus\Settings\NetworkSettingsPage
	 */
	protected $page;

	public function set_up() {
		parent::set_up();
		NetworkStore::flush_cache();
		$this->page = new NetworkSettingsPage();
	}

	public function tear_down() {
		NetworkStore::flush_cache();
		unset( $_POST, $_GET );
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::hooks
	 */
	public function test_hooks_registers_network_admin_menu_and_the_save_action() {
		$hooks = $this->page->hooks();

		$this->assertCount( 2, $hooks );
		$this->assertSame( 'network_admin_menu', $hooks[0]->hook );
		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'network_admin_edit_' . NetworkSettingsPage::SAVE_ACTION, $hooks[1]->hook );
		$this->assertSame( 'action', $hooks[1]->type );
	}

	// -----------------------------------------------------------------------
	// add_menu_page()
	// -----------------------------------------------------------------------

	/**
	 * The menu is not added at all when the capability is denied -- not
	 * added-then-hidden.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::add_menu_page
	 */
	public function test_add_menu_page_does_not_add_page_when_capability_denied() {
		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );
		\WP_Mock::userFunction( 'add_submenu_page' )->never();

		$this->page->add_menu_page();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * With the capability granted, the page is added under Settings in the
	 * network admin with the resolved capability string.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::add_menu_page
	 */
	public function test_add_menu_page_adds_page_when_capability_granted() {
		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( true );

		$captured = null;
		\WP_Mock::userFunction( 'add_submenu_page' )
			->once()
			->andReturnUsing(
				function ( $parent_slug, $page_title, $menu_title, $capability, $menu_slug, $callback ) use ( &$captured ) {
					$captured = compact( 'parent_slug', 'capability', 'menu_slug', 'callback' );
				}
			);

		$this->page->add_menu_page();

		$this->assertSame( 'settings.php', $captured['parent_slug'] );
		$this->assertSame( 'manage_network_options', $captured['capability'] );
		$this->assertSame( NetworkSettingsPage::PAGE_SLUG, $captured['menu_slug'] );
		$this->assertSame( array( $this->page, 'render_page' ), $captured['callback'] );
	}

	// -----------------------------------------------------------------------
	// handle_save() -- non-negotiable per the phase brief
	// -----------------------------------------------------------------------

	/**
	 * A bad nonce rejects the save outright: check_admin_referer() itself
	 * halts (mocked here to throw, standing in for its real wp_die()), and
	 * nothing downstream -- capability check, sanitization, the write --
	 * ever runs.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::handle_save
	 */
	public function test_handle_save_rejects_a_bad_nonce() {
		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( NetworkSettingsPage::NONCE_ACTION )
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'bad nonce' );
				}
			);

		\WP_Mock::userFunction( 'current_user_can' )->never();
		\WP_Mock::userFunction( 'update_network_option' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'bad nonce' );

		$this->page->handle_save();
	}

	/**
	 * A valid nonce but an insufficient capability rejects the save via
	 * wp_die() (mocked here to throw, same technique as the bad-nonce case)
	 * -- the write never happens.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::handle_save
	 */
	public function test_handle_save_rejects_an_insufficient_capability() {
		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( NetworkSettingsPage::NONCE_ACTION )
			->andReturn( 1 );

		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );

		\WP_Mock::userFunction( 'wp_die' )
			->once()
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'denied' );
				}
			);

		\WP_Mock::userFunction( 'update_network_option' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'denied' );

		$this->page->handle_save();
	}

	/**
	 * A valid POST: nonce and capability pass, the input is sanitized and
	 * scoped to exactly the four network-applicable keys (a stray
	 * `is_read_only` in $_POST -- a site-only key -- must never reach the
	 * write), then NetworkStore::save() writes it and the handler redirects
	 * with the updated flag.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::handle_save
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::sanitized_network_input
	 */
	public function test_handle_save_writes_sanitized_network_scoped_values_and_redirects() {
		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()
			->with( NetworkSettingsPage::NONCE_ACTION )
			->andReturn( 1 );

		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( true );

		$_POST = array(
			'auto_archive_enabled'    => '1',
			'auto_archive_days'       => '365',
			'auto_archive_child_mode' => 'locked',
			// scheduled_archive_enabled deliberately absent -- simulates an
			// unchecked checkbox, must sanitize to false, not silently vanish.
			// is_read_only is site-only and must be dropped, not written.
			'is_read_only'            => '1',
		);

		$captured = null;
		\WP_Mock::userFunction( 'update_network_option' )
			->once()
			->andReturnUsing(
				function ( $network_id, $option, $value ) use ( &$captured ) {
					$captured = compact( 'network_id', 'option', 'value' );
					return true;
				}
			);

		\WP_Mock::userFunction( 'network_admin_url' )
			->with( 'settings.php?page=' . NetworkSettingsPage::PAGE_SLUG )
			->andReturn( 'http://example.com/wp-admin/network/settings.php?page=' . NetworkSettingsPage::PAGE_SLUG );
		\WP_Mock::userFunction( 'add_query_arg' )
			->with( 'updated', 'true', 'http://example.com/wp-admin/network/settings.php?page=' . NetworkSettingsPage::PAGE_SLUG )
			->andReturn( 'http://example.com/wp-admin/network/settings.php?page=' . NetworkSettingsPage::PAGE_SLUG . '&updated=true' );

		\WP_Mock::userFunction( 'wp_safe_redirect' )
			->once()
			->with( 'http://example.com/wp-admin/network/settings.php?page=' . NetworkSettingsPage::PAGE_SLUG . '&updated=true' )
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'redirected' );
				}
			);

		try {
			$this->page->handle_save();
			$this->fail( 'Expected the redirect to short-circuit execution.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'redirected', $e->getMessage() );
		}

		$this->assertSame( null, $captured['network_id'] );
		$this->assertSame( NetworkStore::OPTION_KEY, $captured['option'] );
		$this->assertSame( true, $captured['value']['auto_archive_enabled'] );
		$this->assertSame( 365, $captured['value']['auto_archive_days'] );
		$this->assertSame( 'locked', $captured['value']['auto_archive_child_mode'] );
		$this->assertFalse( $captured['value']['scheduled_archive_enabled'], 'An absent checkbox key must sanitize to false, not vanish.' );
		$this->assertArrayNotHasKey( 'is_read_only', $captured['value'], 'A site-only key must never reach the network option.' );
	}

	// -----------------------------------------------------------------------
	// render_page()
	// -----------------------------------------------------------------------

	/**
	 * Nothing renders at all when the capability is denied.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::render_page
	 */
	public function test_render_page_renders_nothing_when_capability_denied() {
		\WP_Mock::userFunction( 'current_user_can' )->once()->andReturn( false );

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The rendered page posts to network_admin_edit.php, includes the nonce
	 * field, and renders both the cascade fieldset (folding
	 * auto_archive_child_mode into it, phrased for "sites") and every
	 * simple network field.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::render_page
	 */
	public function test_render_page_renders_the_form_cascade_field_and_simple_fields() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );
		\WP_Mock::userFunction( 'network_admin_url' )
			->with( 'edit.php?action=' . NetworkSettingsPage::SAVE_ACTION )
			->andReturn( 'http://example.com/wp-admin/network/edit.php?action=' . NetworkSettingsPage::SAVE_ACTION );
		\WP_Mock::userFunction( 'wp_nonce_field' )->once()->with( NetworkSettingsPage::NONCE_ACTION );
		\WP_Mock::userFunction( 'submit_button' )->once();

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString(
			'<form method="post" action="http://example.com/wp-admin/network/edit.php?action=' . NetworkSettingsPage::SAVE_ACTION . '">',
			$output
		);
		$this->assertStringContainsString( 'name="auto_archive_days"', $output );
		$this->assertStringContainsString( 'name="auto_archive_child_mode"', $output );
		$this->assertStringContainsString( 'aps-cascade-downstream', $output );
		$this->assertStringContainsString( 'name="scheduled_archive_enabled"', $output );
		$this->assertStringContainsString( 'name="auto_archive_enabled"', $output );
		$this->assertStringContainsString( 'sites', $output, 'The downstream selector must be phrased for the level below the network: sites.' );
	}

	/**
	 * A stored `auto_archive_child_mode` value ChildMode::tryFrom() cannot
	 * resolve (corrupt/stale option data) falls back to Open rather than
	 * fataling on a null enum -- mirrors SettingsPageTest's identical case
	 * for the site screen.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::render_page
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::render_cascade_field
	 */
	public function test_render_page_falls_back_to_open_for_an_unrecognized_stored_child_mode() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array( 'auto_archive_child_mode' => 'not-a-real-mode' ) );
		\WP_Mock::userFunction( 'network_admin_url' )->andReturn( 'http://example.com/wp-admin/network/edit.php' );
		\WP_Mock::userFunction( 'wp_nonce_field' )->once();
		\WP_Mock::userFunction( 'submit_button' )->once();

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertMatchesRegularExpression( '/value="open" checked="checked"/', $output );
	}

	/**
	 * `?updated=true` after a successful save renders the confirmation
	 * notice.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::render_page
	 */
	public function test_render_page_shows_the_updated_notice_after_a_save() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );
		\WP_Mock::userFunction( 'network_admin_url' )->andReturn( 'http://example.com/wp-admin/network/edit.php' );
		\WP_Mock::userFunction( 'wp_nonce_field' )->once();
		\WP_Mock::userFunction( 'submit_button' )->once();

		$_GET['updated'] = 'true';

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'notice-success', $output );
	}

	/**
	 * Without `?updated=true`, no confirmation notice renders.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsPage::render_page
	 */
	public function test_render_page_omits_the_updated_notice_without_the_query_arg() {
		\WP_Mock::userFunction( 'current_user_can' )->andReturn( true );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn( array() );
		\WP_Mock::userFunction( 'network_admin_url' )->andReturn( 'http://example.com/wp-admin/network/edit.php' );
		\WP_Mock::userFunction( 'wp_nonce_field' )->once();
		\WP_Mock::userFunction( 'submit_button' )->once();

		ob_start();
		$this->page->render_page();
		$output = ob_get_clean();

		$this->assertStringNotContainsString( 'notice-success', $output );
	}
}
