<?php
/**
 * Settings\NetworkSettingsCapability Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\NetworkSettingsCapability
 *
 * Mirrors SettingsCapabilityTest's shape exactly, one level up the cascade.
 */

use ArchivedPostStatus\Settings\NetworkSettingsCapability;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\NetworkSettingsCapability
 */
class NetworkSettingsCapabilityTest extends TestCase {

	/**
	 * Default path: without any filter override, `manage_network_options`
	 * is the capability sent to `current_user_can()`.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsCapability::granted
	 */
	public function test_granted_defaults_to_manage_network_options() {
		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return true;
				},
			)
		);

		\WP_Mock::expectFilter( 'aps_default_network_settings_capability', 'manage_network_options' );

		$this->assertTrue( NetworkSettingsCapability::granted() );
		$this->assertSame( 'manage_network_options', $received_capability );
	}

	/**
	 * `aps_default_network_settings_capability` replaces the capability
	 * `current_user_can()` sees.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsCapability::granted
	 */
	public function test_granted_filter_replaces_capability() {
		\WP_Mock::onFilter( 'aps_default_network_settings_capability' )
			->with( 'manage_network_options' )
			->reply( 'manage_options' );

		$received_capability = null;

		\WP_Mock::userFunction(
			'current_user_can',
			array(
				'times'  => 1,
				'return' => function ( $capability ) use ( &$received_capability ) {
					$received_capability = $capability;
					return false;
				},
			)
		);

		$this->assertFalse( NetworkSettingsCapability::granted() );
		$this->assertSame( 'manage_options', $received_capability );
	}

	/**
	 * capability() returns the resolved string itself -- what
	 * `add_submenu_page()` needs, not a bool.
	 *
	 * @covers ArchivedPostStatus\Settings\NetworkSettingsCapability::capability
	 */
	public function test_capability_returns_the_resolved_string() {
		\WP_Mock::expectFilter( 'aps_default_network_settings_capability', 'manage_network_options' );

		$this->assertSame( 'manage_network_options', NetworkSettingsCapability::capability() );
	}
}
