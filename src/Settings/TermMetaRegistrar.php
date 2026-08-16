<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\TermMeta;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Registers both {@see TermMeta} keys as REST-visible term meta for every
 * opted-in taxonomy — the term-level mirror of
 * {@see \ArchivedPostStatus\Schedule\MetaRegistrar}.
 *
 * Runs on plain `init`, unlike {@see TermFields}'s `wp_loaded` deferral:
 * `register_term_meta()` only stores configuration keyed by taxonomy slug —
 * unlike enumerating `get_taxonomies()`, it does not require the named
 * taxonomy to already be registered, so there is no ordering hazard against
 * a custom taxonomy registered at a later `init` priority.
 *
 * Unconditional — not gated on `is_admin()` or `WP_CLI` — because REST is
 * neither: a request that never touches wp-admin still needs these meta
 * keys registered before `WP_REST_Terms_Controller` can read or write them.
 *
 * The `auth_callback` for each key is gated on the taxonomy's OWN
 * `manage_terms` capability via {@see TermCapability}, resolved per
 * taxonomy — never a single hardcoded capability shared by every taxonomy.
 *
 * @since 0.5.0
 */
final class TermMetaRegistrar implements HookableInterface {

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructor.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'init', array( $this, 'register_meta' ) ),
		);
	}

	/**
	 * `init` callback: registers both TermMeta keys for every opted-in
	 * taxonomy.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( self::taxonomies() as $taxonomy ) {
			register_term_meta( $taxonomy, TermMeta::META_DAYS, self::meta_args( $taxonomy, 'integer' ) );
			register_term_meta( $taxonomy, TermMeta::META_CHILD_MODE, self::meta_args( $taxonomy, 'string' ) );
		}
	}

	/**
	 * The `register_term_meta()` args shared by both keys for one taxonomy,
	 * differing only in `type`.
	 *
	 * @since 0.5.0
	 * @param string $taxonomy The taxonomy slug this auth_callback closes over.
	 * @param string $type     'integer' or 'string'.
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- TermCapability is the canonical term-capability accessor.
	 */
	private static function meta_args( string $taxonomy, string $type ): array {
		return array(
			'type'          => $type,
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => static fn (): bool => TermCapability::granted( $taxonomy ),
		);
	}

	/**
	 * The configured `auto_archive_taxonomies` opt-in list, read the same
	 * way {@see \ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider}
	 * reads its own settings.
	 *
	 * @since 0.5.0
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private static function taxonomies(): array {
		$taxonomies = apply_filters( 'aps_auto_archive_taxonomies', Schema::default_for( 'auto_archive_taxonomies' ) );

		return is_array( $taxonomies ) ? $taxonomies : array();
	}
}
