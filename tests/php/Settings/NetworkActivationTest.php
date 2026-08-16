<?php
/**
 * Settings\NetworkActivation Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\NetworkActivation
 *
 * The phase-7 trap this class exists to dodge: `is_plugin_active_for_network()`
 * lives in `wp-admin/includes/plugin.php`, never loaded on a front-end, cron,
 * or REST request. None of these tests define or stub that function, and
 * every scenario below still resolves cleanly -- proof this class never
 * calls it.
 */

use ArchivedPostStatus\Settings\NetworkActivation;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\NetworkActivation
 */
class NetworkActivationTest extends TestCase {

	/**
	 * is_multisite() must short-circuit before anything else -- a
	 * single-site install never touches get_site_option() at all.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkActivation::active
	 */
	public function test_active_returns_false_and_never_reads_site_option_when_not_multisite() {
		\WP_Mock::userFunction( 'is_multisite' )->once()->andReturn( false );
		\WP_Mock::userFunction( 'get_site_option' )->never();

		$this->assertFalse( NetworkActivation::active() );
	}

	/**
	 * Multisite, but this plugin's basename is absent from the
	 * network-active plugin list: not network-activated.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkActivation::active
	 */
	public function test_active_returns_false_when_multisite_but_plugin_not_in_active_sitewide_plugins() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( 'some-other-plugin/some-other-plugin.php' => true ) );

		$this->assertFalse( NetworkActivation::active() );
	}

	/**
	 * Multisite, empty active-sitewide-plugins list (a fresh network):
	 * false, no fatal on a missing key.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkActivation::active
	 */
	public function test_active_returns_false_when_active_sitewide_plugins_is_empty() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array() );

		$this->assertFalse( NetworkActivation::active() );
	}

	/**
	 * Multisite, this plugin's basename IS in the list: network-activated.
	 * `ARCHIVED_POST_STATUS_PLUGIN` is defined by the bootstrap's own
	 * require of `archived-post-status.php`, so this exercises the real
	 * constant, not the fallback.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkActivation::active
	 */
	public function test_active_returns_true_when_plugin_is_in_active_sitewide_plugins() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => 1699999999 ) );

		$this->assertTrue( NetworkActivation::active() );
	}

	/**
	 * No fatal despite `is_plugin_active_for_network()` being wholly
	 * undefined in the test runtime -- this class never calls it. See the
	 * class docblock.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkActivation::active
	 */
	public function test_active_never_calls_is_plugin_active_for_network() {
		$this->assertFalse(
			function_exists( 'is_plugin_active_for_network' ),
			'This test only proves something if the function genuinely is not defined in the test runtime.'
		);

		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );

		$this->assertTrue( NetworkActivation::active(), 'Resolves without fataling on the missing wp-admin function.' );
	}
}
