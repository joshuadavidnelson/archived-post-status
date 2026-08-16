<?php
/**
 * Settings\SettingsCapability Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\SettingsCapability
 *
 * Mirrors ArchiveCapabilityTest's shape: default-path and filter-override
 * cases, both pinning the exact capability string handed to
 * current_user_can().
 */

use ArchivedPostStatus\Settings\SettingsCapability;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\SettingsCapability
 */
class SettingsCapabilityTest extends TestCase {

	/**
	 * Default path: without any filter override, `manage_options` is the
	 * capability sent to `current_user_can()`.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsCapability::granted
	 */
	public function test_granted_defaults_to_manage_options() {
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

		\WP_Mock::expectFilter( 'aps_default_settings_capability', 'manage_options' );

		$this->assertTrue( SettingsCapability::granted() );
		$this->assertSame( 'manage_options', $received_capability );
	}

	/**
	 * `aps_default_settings_capability` replaces the capability
	 * `current_user_can()` sees.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsCapability::granted
	 */
	public function test_granted_filter_replaces_capability() {
		\WP_Mock::onFilter( 'aps_default_settings_capability' )
			->with( 'manage_options' )
			->reply( 'manage_network_options' );

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

		$this->assertFalse( SettingsCapability::granted() );
		$this->assertSame( 'manage_network_options', $received_capability );
	}
}
