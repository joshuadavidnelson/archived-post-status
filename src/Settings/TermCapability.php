<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves whether the current user is permitted to manage one taxonomy's
 * term-level auto-archive rule.
 *
 * Gates on the taxonomy's OWN `manage_terms` capability, resolved from the
 * taxonomy object — never hardcoded to `manage_categories` — the same
 * primitive-resolution idea as
 * {@see \ArchivedPostStatus\Archive\PostTypeCapabilityPrimitive}. A taxonomy
 * with no cap map entry (or an unregistered taxonomy, e.g. a deactivated
 * custom-taxonomy plugin whose term meta this site still stores) falls back
 * to `manage_categories`, core's own default for an unmapped taxonomy
 * capability.
 *
 * @since 0.5.0
 */
final class TermCapability {

	/**
	 * Whether the current user can manage one taxonomy's term-level rule.
	 *
	 * @since 0.5.0
	 * @param string $taxonomy The taxonomy slug.
	 * @return bool
	 */
	public static function granted( string $taxonomy ): bool {
		return current_user_can( self::capability( $taxonomy ) );
	}

	/**
	 * The resolved capability string itself — not a check. Used wherever
	 * WordPress core wants the capability, not a bool: a REST meta
	 * `auth_callback`.
	 *
	 * @since 0.5.0
	 * @param string $taxonomy The taxonomy slug.
	 * @return string
	 */
	public static function capability( string $taxonomy ): string {
		$taxonomy_object = get_taxonomy( $taxonomy );
		$default         = ( $taxonomy_object && isset( $taxonomy_object->cap->manage_terms ) )
			? $taxonomy_object->cap->manage_terms
			: 'manage_categories';

		/**
		 * Default capability required to manage one taxonomy's term-level
		 * auto-archive rule.
		 *
		 * @since 0.5.0
		 * @param string $capability Default: the taxonomy's own `manage_terms`
		 *                            capability.
		 * @param string $taxonomy   The taxonomy slug.
		 */
		return (string) apply_filters( 'aps_default_term_rule_capability', $default, $taxonomy );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
