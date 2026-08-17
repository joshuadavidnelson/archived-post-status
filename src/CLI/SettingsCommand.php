<?php
/**
 * `wp aps settings` commands — list, get, update.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\Settings\NetworkStore;
use ArchivedPostStatus\Settings\Sanitizer;
use ArchivedPostStatus\Settings\Schema;
use ArchivedPostStatus\Settings\Store;
use WP_CLI;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Reads and writes plugin settings through the same {@see Schema}/{@see
 * Sanitizer} boundary the settings screen and REST use — never a raw
 * `update_option()`.
 *
 * `--network` targets {@see NetworkStore} instead of {@see Store}, and is
 * refused outright on a non-multisite install rather than silently writing
 * a network option no code on that install will ever read back. A key not
 * applicable at the requested level (e.g. `auto_archive_taxonomies`, site-
 * only, under `--network`) is rejected the same way an entirely unknown key
 * is — {@see Schema::keys_for_level()} is the one source of truth for "valid
 * here" at either level.
 *
 * `update_setting()` is the one method here that changes anything, so it is
 * the one gated on `aps_current_user_can_manage_settings()` /
 * `aps_current_user_can_manage_network_settings()` — the same functions
 * {@see \ArchivedPostStatus\Settings\SettingsPage} and {@see
 * \ArchivedPostStatus\Settings\NetworkSettingsPage} gate their own save
 * handlers on, and the same anonymous-CLI bypass {@see
 * Command::capability_check()} uses: WP-CLI's default server context is
 * privileged by design, so only a real, logged-in `--user=<id>` is checked.
 * `list_settings()`/`get_setting()` stay ungated, matching {@see
 * ExplainCommand}'s precedent — they are reads of already-effective
 * configuration, not writes.
 *
 * @since 0.5.0
 */
final class SettingsCommand {

	/**
	 * `wp aps settings list [--network] [--format=<format>]`
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Unused; `list` takes no positional args.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags (--network, --format).
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked Registrar delegate signature, matching every other command method in this namespace.
	 */
	public function list_settings( array $args, array $assoc_args ): void {
		$network = $this->network_flag( $assoc_args );
		if ( $network && ! $this->assert_multisite() ) {
			return;
		}

		$level = $network ? Schema::LEVEL_NETWORK : Schema::LEVEL_SITE;
		$items = array_map(
			fn ( string $key ): array => array( 'key' => $key, 'value' => $this->display_value( $key, $network ) ),
			Schema::keys_for_level( $level )
		);

		// WP_CLI\Utils is only loaded under a real WP-CLI request; not a
		// composer dependency, same gap the existing phpstan.neon.dist
		// ignoreErrors entries cover for get_flag_value()/make_progress_bar().
		// @phpstan-ignore function.notFound
		Utils\format_items( (string) Utils\get_flag_value( $assoc_args, 'format', 'table' ), $items, array( 'key', 'value' ) );
	}

	/**
	 * `wp aps settings get <key> [--network]`
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the key.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags (--network).
	 * @return void
	 */
	public function get_setting( array $args, array $assoc_args ): void {
		$network = $this->network_flag( $assoc_args );
		if ( $network && ! $this->assert_multisite() ) {
			return;
		}

		$key = (string) ( $args[0] ?? '' );
		if ( ! $this->assert_known_key( $key, $network ) ) {
			return;
		}

		// WP_CLI is only loaded under a real WP-CLI request; see this class's ::error() calls below.
		// @phpstan-ignore class.notFound
		WP_CLI::log( $this->display_value( $key, $network ) );
	}

	/**
	 * `wp aps settings update <key> <value> [--network]`
	 *
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the key, $args[1] the raw value.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags (--network).
	 * @return void
	 */
	public function update_setting( array $args, array $assoc_args ): void {
		$network = $this->network_flag( $assoc_args );
		if ( $network && ! $this->assert_multisite() ) {
			return;
		}

		if ( ! $this->assert_capable( $network ) ) {
			return;
		}

		$key = (string) ( $args[0] ?? '' );
		if ( ! $this->assert_known_key( $key, $network ) ) {
			return;
		}

		$sanitized = $this->sanitized_value( $key, (string) ( $args[1] ?? '' ) );
		$this->write_setting( $network, $key, $sanitized );

		WP_CLI::success( "Updated {$key} to " . $this->format_value( $sanitized ) . '.' );
	}

	/**
	 * @since 0.5.0
	 * @param array<string, mixed> $assoc_args Associative CLI flags.
	 * @return bool
	 */
	private function network_flag( array $assoc_args ): bool {
		return (bool) Utils\get_flag_value( $assoc_args, 'network', false );
	}

	/**
	 * @since 0.5.0
	 * @return bool True if the install is multisite; false after reporting the error.
	 */
	private function assert_multisite(): bool {
		if ( is_multisite() ) {
			return true;
		}

		// WP_CLI is only loaded under a real WP-CLI request; not a composer
		// dependency, same gap the existing phpstan.neon.dist ignoreErrors
		// entries cover for ::success()/::warning()/::add_command().
		// @phpstan-ignore class.notFound
		WP_CLI::error( '--network requires a multisite install.' );
		return false;
	}

	/**
	 * Enforce the settings capability for `wp --user=<id> aps settings
	 * update`. Anonymous CLI (user 0) bypasses the check, matching {@see
	 * Command::capability_check()} and core `wp option update` — WP-CLI's
	 * default server context is privileged by design.
	 *
	 * @since 0.5.0
	 * @param bool $network Whether --network was passed.
	 * @return bool True if the user is permitted; false after reporting the error.
	 */
	private function assert_capable( bool $network ): bool {
		if ( ! is_user_logged_in() ) {
			return true;
		}

		$capable = $network
			? aps_current_user_can_manage_network_settings()
			: aps_current_user_can_manage_settings();

		if ( $capable ) {
			return true;
		}

		// WP_CLI is only loaded under a real WP-CLI request; see assert_multisite() above.
		// @phpstan-ignore class.notFound
		WP_CLI::error( 'You do not have permission to manage ' . ( $network ? 'network ' : '' ) . 'settings.' );
		return false;
	}

	/**
	 * @since 0.5.0
	 * @param string $key     The setting key.
	 * @param bool   $network Whether --network was passed.
	 * @return bool True if $key is valid at this level; false after reporting the error.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private function assert_known_key( string $key, bool $network ): bool {
		$valid = Schema::keys_for_level( $network ? Schema::LEVEL_NETWORK : Schema::LEVEL_SITE );

		if ( in_array( $key, $valid, true ) ) {
			return true;
		}

		// WP_CLI is only loaded under a real WP-CLI request; see assert_multisite() above.
		// @phpstan-ignore class.notFound
		WP_CLI::error( "Unknown setting \"{$key}\". Valid keys: " . implode( ', ', $valid ) . '.' );
		return false;
	}

	/**
	 * @since 0.5.0
	 * @param string $key     The setting key.
	 * @param bool   $network Whether --network was passed.
	 * @return mixed
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Store/NetworkStore are the canonical settings-value accessors.
	 */
	private function raw_value( string $key, bool $network ): mixed {
		return $network ? NetworkStore::get( $key ) : Store::get( $key );
	}

	/**
	 * @since 0.5.0
	 * @param bool   $network Whether --network was passed.
	 * @param string $key     The setting key.
	 * @param mixed  $value   The already-sanitized value to store.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Store/NetworkStore are the canonical settings-value accessors.
	 */
	private function write_setting( bool $network, string $key, mixed $value ): void {
		if ( $network ) {
			NetworkStore::update( $key, $value );
			return;
		}

		Store::update( $key, $value );
	}

	/**
	 * @since 0.5.0
	 * @param string $key     The setting key.
	 * @param bool   $network Whether --network was passed.
	 * @return string
	 */
	private function display_value( string $key, bool $network ): string {
		return $this->format_value( $this->raw_value( $key, $network ) );
	}

	/**
	 * @since 0.5.0
	 * @param mixed $value A sanitized setting value.
	 * @return string
	 */
	private function format_value( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_array( $value ) ) {
			return implode( ',', $value );
		}

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Sanitize a raw CLI value string through the same {@see Schema}/{@see
	 * Sanitizer} boundary the settings screen and REST use.
	 *
	 * @since 0.5.0
	 * @param string $key The setting key; already validated by {@see assert_known_key()}.
	 * @param string $raw The raw CLI argument.
	 * @return mixed
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Sanitizer/Schema are the canonical settings-boundary accessors.
	 */
	private function sanitized_value( string $key, string $raw ): mixed {
		$sanitized = Sanitizer::sanitize( array( $key => $this->coerce_cli_value( $key, $raw ) ) );

		return $sanitized[ $key ] ?? Schema::default_for( $key );
	}

	/**
	 * Coerce a raw CLI string into the shape {@see Schema}'s sanitizer for
	 * $key expects, before sanitizing. CLI arguments always arrive as plain
	 * strings: a bool key's sanitizer does a bare `(bool)` cast, which would
	 * treat the literal string "false" as truthy; an array key's sanitizer
	 * expects a PHP array, not a comma-separated string; a nullable-int
	 * key's "unset" state has no string spelling of its own without this.
	 *
	 * @since 0.5.0
	 * @param string $key The setting key.
	 * @param string $raw The raw CLI argument.
	 * @return mixed
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private function coerce_cli_value( string $key, string $raw ): mixed {
		$default = Schema::default_for( $key );

		if ( is_bool( $default ) ) {
			return in_array( strtolower( $raw ), array( '1', 'true', 'yes', 'on' ), true );
		}

		if ( is_array( $default ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ) ) );
		}

		if ( ( null === $default || is_int( $default ) ) && in_array( strtolower( $raw ), array( '', 'null', 'none' ), true ) ) {
			return null;
		}

		return $raw;
	}
}
