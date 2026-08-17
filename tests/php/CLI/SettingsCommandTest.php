<?php
/**
 * SettingsCommand tests.
 *
 * Every read/write goes through the real Schema/Sanitizer/Store/NetworkStore
 * classes — only the WordPress option functions those classes call are
 * mocked — so these tests exercise the actual sanitization boundary, not a
 * stand-in for it.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\SettingsCommand
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value/format_items polyfills.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\CLI\SettingsCommand;
	use ArchivedPostStatus\Settings\NetworkStore;
	use ArchivedPostStatus\Settings\Schema;
	use ArchivedPostStatus\Settings\Store;

	/**
	 * @since 0.5.0
	 * @covers ArchivedPostStatus\CLI\SettingsCommand
	 */
	class SettingsCommandTest extends TestCase {

		private SettingsCommand $cmd;

		public function set_up() {
			parent::set_up();
			$this->cmd = new SettingsCommand();
			\WP_CLI::reset();
			global $aps_test_format_items_calls;
			$aps_test_format_items_calls = array();
			Store::flush_cache();
			NetworkStore::flush_cache();
		}

		public function tear_down() {
			Store::flush_cache();
			NetworkStore::flush_cache();
			parent::tear_down();
		}

		// ---------------------------------------------------------------
		// list_settings()
		// ---------------------------------------------------------------

		/**
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::list_settings
		 */
		public function test_list_settings_lists_every_site_level_key_with_its_default_value() {
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );

			$this->cmd->list_settings( array(), array() );

			global $aps_test_format_items_calls;
			$this->assertCount( 1, $aps_test_format_items_calls );
			[ $format, $items, $fields ] = $aps_test_format_items_calls[0];

			$this->assertSame( 'table', $format );
			$this->assertSame( array( 'key', 'value' ), $fields );
			$this->assertCount( count( Schema::keys_for_level( Schema::LEVEL_SITE ) ), $items );

			$is_read_only = array_values( array_filter( $items, static fn ( array $row ): bool => 'is_read_only' === $row['key'] ) )[0];
			$this->assertSame( 'true', $is_read_only['value'], 'is_read_only defaults to true' );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::list_settings
		 */
		public function test_list_settings_with_network_lists_only_network_level_keys() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
			\WP_Mock::userFunction( 'get_network_option' )->with( null, NetworkStore::OPTION_KEY, array() )->andReturn( array() );

			$this->cmd->list_settings( array(), array( 'network' => true ) );

			global $aps_test_format_items_calls;
			[ , $items ] = $aps_test_format_items_calls[0];
			$this->assertCount( count( Schema::keys_for_level( Schema::LEVEL_NETWORK ) ), $items );

			$keys = array_column( $items, 'key' );
			$this->assertNotContains( 'auto_archive_taxonomies', $keys, 'a site-only key must not appear under --network' );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::list_settings
		 */
		public function test_list_settings_with_network_on_non_multisite_refuses_cleanly() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
			\WP_Mock::userFunction( 'get_network_option' )->never();

			$this->cmd->list_settings( array(), array( 'network' => true ) );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'multisite', \WP_CLI::$errors[0] );

			global $aps_test_format_items_calls;
			$this->assertSame( array(), $aps_test_format_items_calls );
		}

		// ---------------------------------------------------------------
		// get_setting()
		// ---------------------------------------------------------------

		/**
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::get_setting
		 */
		public function test_get_setting_prints_the_current_value() {
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array( 'auto_archive_grace_days' => 14 ) );

			$this->cmd->get_setting( array( 'auto_archive_grace_days' ), array() );

			$this->assertSame( array( '14' ), \WP_CLI::$logs );
		}

		/**
		 * An unknown key errors, naming the valid keys, rather than reading
		 * or writing anything.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::get_setting
		 */
		public function test_get_setting_with_unknown_key_errors_and_names_the_valid_keys() {
			\WP_Mock::userFunction( 'get_option' )->never();

			$this->cmd->get_setting( array( 'not_a_real_key' ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'not_a_real_key', \WP_CLI::$errors[0] );
			$this->assertStringContainsString( 'auto_archive_grace_days', \WP_CLI::$errors[0] );
			$this->assertSame( array(), \WP_CLI::$logs );
		}

		/**
		 * A key that is real but not applicable at the requested level is
		 * rejected the same way an entirely unknown key is.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::get_setting
		 */
		public function test_get_setting_rejects_a_site_only_key_under_network() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
			\WP_Mock::userFunction( 'get_network_option' )->never();

			$this->cmd->get_setting( array( 'auto_archive_taxonomies' ), array( 'network' => true ) );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'auto_archive_taxonomies', \WP_CLI::$errors[0] );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::get_setting
		 */
		public function test_get_setting_with_network_on_non_multisite_refuses_cleanly() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
			\WP_Mock::userFunction( 'get_network_option' )->never();

			$this->cmd->get_setting( array( 'auto_archive_days' ), array( 'network' => true ) );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'multisite', \WP_CLI::$errors[0] );
		}

		// ---------------------------------------------------------------
		// update_setting()
		// ---------------------------------------------------------------

		/**
		 * A bool key's sanitizer does a bare (bool) cast, so the raw CLI
		 * string "true" must be coerced before it reaches the sanitizer —
		 * otherwise the literal string "false" would also cast truthy.
		 * This pins both directions.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_coerces_cli_bool_strings_correctly() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );
			$captured = null;
			\WP_Mock::userFunction( 'update_option' )
				->once()
				->andReturnUsing(
					static function ( $option, $value ) use ( &$captured ) {
						$captured = $value;
						return true;
					}
				);

			$this->cmd->update_setting( array( 'auto_archive_enabled', 'false' ), array() );

			$this->assertFalse( $captured['auto_archive_enabled'], '"false" the CLI string must sanitize to boolean false, not truthy' );
			$this->assertCount( 1, \WP_CLI::$successes );
			$this->assertStringContainsString( 'auto_archive_enabled', \WP_CLI::$successes[0] );
			$this->assertStringContainsString( 'false', \WP_CLI::$successes[0] );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_coerces_a_truthy_cli_string_to_true() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );
			$captured = null;
			\WP_Mock::userFunction( 'update_option' )->once()->andReturnUsing(
				static function ( $option, $value ) use ( &$captured ) {
					$captured = $value;
					return true;
				}
			);

			$this->cmd->update_setting( array( 'auto_archive_enabled', 'true' ), array() );

			$this->assertTrue( $captured['auto_archive_enabled'] );
		}

		/**
		 * An array-typed key accepts a comma-separated CLI string and
		 * sanitizes it through the real post_types_sanitizer.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_splits_a_comma_separated_value_for_an_array_key() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );
			\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static fn ( $v ) => (string) $v );
			\WP_Mock::userFunction( 'aps_get_supported_post_types' )->andReturn( array( 'post', 'page' ) );
			$captured = null;
			\WP_Mock::userFunction( 'update_option' )->once()->andReturnUsing(
				static function ( $option, $value ) use ( &$captured ) {
					$captured = $value;
					return true;
				}
			);

			$this->cmd->update_setting( array( 'scheduled_archive_post_types', 'post, page' ), array() );

			$this->assertSame( array( 'post', 'page' ), $captured['scheduled_archive_post_types'] );
		}

		/**
		 * A nullable-int key's empty-string CLI value clears it to null
		 * ("unset", not "zero") rather than sanitizing "" into a number.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_treats_an_empty_string_as_clearing_a_nullable_int_key() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array( 'auto_archive_days' => 30 ) );
			$captured = null;
			\WP_Mock::userFunction( 'update_option' )->once()->andReturnUsing(
				static function ( $option, $value ) use ( &$captured ) {
					$captured = $value;
					return true;
				}
			);

			$this->cmd->update_setting( array( 'auto_archive_days', '' ), array() );

			$this->assertNull( $captured['auto_archive_days'] );
		}

		/**
		 * A numeric CLI string for the same nullable-int key sanitizes
		 * through absint()/max(1, …) exactly as the settings screen would.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_sanitizes_a_numeric_value_for_a_nullable_int_key() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );
			\WP_Mock::userFunction( 'absint' )->with( '30' )->andReturn( 30 );
			$captured = null;
			\WP_Mock::userFunction( 'update_option' )->once()->andReturnUsing(
				static function ( $option, $value ) use ( &$captured ) {
					$captured = $value;
					return true;
				}
			);

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array() );

			$this->assertSame( 30, $captured['auto_archive_days'] );
		}

		/**
		 * An unknown key errors and never reaches update_option() —
		 * proves the key gate runs before any write, not merely before a
		 * successful one.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_with_unknown_key_errors_without_writing_anything() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->never();
			\WP_Mock::userFunction( 'update_option' )->never();

			$this->cmd->update_setting( array( 'not_a_real_key', 'whatever' ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'not_a_real_key', \WP_CLI::$errors[0] );
			$this->assertCount( 0, \WP_CLI::$successes );
		}

		/**
		 * --network on a non-multisite install is refused before any option
		 * function is touched, network or otherwise.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_with_network_on_non_multisite_refuses_cleanly() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
			\WP_Mock::userFunction( 'get_network_option' )->never();
			\WP_Mock::userFunction( 'update_network_option' )->never();
			\WP_Mock::userFunction( 'get_option' )->never();
			\WP_Mock::userFunction( 'update_option' )->never();

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array( 'network' => true ) );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'multisite', \WP_CLI::$errors[0] );
		}

		/**
		 * --network on a multisite install writes through NetworkStore, not
		 * Store — the two option keys must never be conflated.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_with_network_writes_through_network_store() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
			\WP_Mock::userFunction( 'get_network_option' )->with( null, NetworkStore::OPTION_KEY, array() )->andReturn( array() );
			\WP_Mock::userFunction( 'get_option' )->never();
			\WP_Mock::userFunction( 'update_option' )->never();
			\WP_Mock::userFunction( 'absint' )->with( '30' )->andReturn( 30 );
			$captured_option = null;
			\WP_Mock::userFunction( 'update_network_option' )->once()->andReturnUsing(
				static function ( $network_id, $option, $value ) use ( &$captured_option ) {
					$captured_option = $option;
					return true;
				}
			);
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array( 'network' => true ) );

			$this->assertSame( NetworkStore::OPTION_KEY, $captured_option );
			$this->assertCount( 1, \WP_CLI::$successes );
		}

		// ---------------------------------------------------------------
		// update_setting() — capability gate (F2: settings writes were unguarded)
		// ---------------------------------------------------------------

		/**
		 * Anonymous CLI (no authenticated user) bypasses the capability
		 * check entirely, matching {@see Command::capability_check()} and
		 * core `wp option update` — WP-CLI's default server context is
		 * privileged by design.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_skips_the_capability_check_when_no_user_authenticated() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );
			\WP_Mock::userFunction( 'absint' )->with( '30' )->andReturn( 30 );
			\WP_Mock::userFunction( 'update_option' )->once()->andReturn( true );

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array() );

			$this->assertCount( 1, \WP_CLI::$successes );
			$this->assertCount( 0, \WP_CLI::$errors );
		}

		/**
		 * A logged-in user lacking `aps_current_user_can_manage_settings()`
		 * is rejected before any option function runs.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_rejects_a_logged_in_user_without_the_settings_capability() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_manage_settings' )->andReturn( false );
			\WP_Mock::userFunction( 'get_option' )->never();
			\WP_Mock::userFunction( 'update_option' )->never();

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'permission', \WP_CLI::$errors[0] );
			$this->assertCount( 0, \WP_CLI::$successes );
		}

		/**
		 * A logged-in user WITH the settings capability proceeds normally.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_permits_a_logged_in_user_with_the_settings_capability() {
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_manage_settings' )->andReturn( true );
			\WP_Mock::userFunction( 'get_option' )->with( Store::OPTION_KEY, array() )->andReturn( array() );
			\WP_Mock::userFunction( 'absint' )->with( '30' )->andReturn( 30 );
			\WP_Mock::userFunction( 'update_option' )->once()->andReturn( true );

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array() );

			$this->assertCount( 1, \WP_CLI::$successes );
		}

		/**
		 * Under --network, the capability check routes to the NETWORK
		 * capability function, not the site one — a site admin without
		 * `manage_network_options` must not be able to write network
		 * settings just because they hold `manage_options`.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_with_network_checks_the_network_capability_not_the_site_one() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
			\WP_Mock::userFunction( 'is_user_logged_in' )->andReturn( true );
			\WP_Mock::userFunction( 'aps_current_user_can_manage_settings' )->never();
			\WP_Mock::userFunction( 'aps_current_user_can_manage_network_settings' )->once()->andReturn( false );
			\WP_Mock::userFunction( 'get_network_option' )->never();
			\WP_Mock::userFunction( 'update_network_option' )->never();

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array( 'network' => true ) );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'network', \WP_CLI::$errors[0] );
		}

		/**
		 * The --network multisite gate runs before the capability check —
		 * a non-multisite install refuses cleanly without ever consulting
		 * is_user_logged_in() or either capability function.
		 *
		 * @covers ArchivedPostStatus\CLI\SettingsCommand::update_setting
		 */
		public function test_update_setting_checks_multisite_before_capability() {
			\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
			\WP_Mock::userFunction( 'is_user_logged_in' )->never();
			\WP_Mock::userFunction( 'aps_current_user_can_manage_network_settings' )->never();

			$this->cmd->update_setting( array( 'auto_archive_days', '30' ), array( 'network' => true ) );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( 'multisite', \WP_CLI::$errors[0] );
		}
	}
}
