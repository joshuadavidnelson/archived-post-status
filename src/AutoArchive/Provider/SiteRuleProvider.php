<?php

namespace ArchivedPostStatus\AutoArchive\Provider;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleProviderInterface;
use ArchivedPostStatus\Settings\Schema;

/**
 * The site level of the auto-archive cascade.
 *
 * Reads the `auto_archive_*` site settings through the `aps_*` filters
 * {@see \ArchivedPostStatus\Settings\HookAdapter} exposes, not through
 * {@see \ArchivedPostStatus\Settings\Store} directly — the same boundary
 * every other reader of a plugin setting goes through, and what lets a
 * developer override at priority ≤ 10 win over a stored value.
 *
 * @since 0.5.0
 */
final class SiteRuleProvider implements RuleProviderInterface {

	/**
	 * @since 0.5.0
	 * @return string Always 'site'. The dynamic `aps_auto_archive_{$level}_rule`
	 *                 hook in {@see \ArchivedPostStatus\AutoArchive\RuleChain}
	 *                 depends on this literal value.
	 */
	public function level(): string {
		return 'site';
	}

	/**
	 * The site's single rule for one post, or none.
	 *
	 * Empty when auto-archive is disabled site-wide, or when the post's type
	 * is not one of the explicitly opted-in `auto_archive_types` — an empty
	 * type list means no post type is opted in, never "every type".
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return Rule[] Zero or one Rule.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- ChildMode's own tryFrom()/Open are the canonical way to hydrate the enum.
	 */
	public function rules_for( int $post_id ): array {
		if ( ! (bool) self::setting( 'auto_archive_enabled' ) ) {
			return array();
		}

		$types = self::setting( 'auto_archive_types' );
		$types = is_array( $types ) ? $types : array();

		if ( ! in_array( get_post_type( $post_id ), $types, true ) ) {
			return array();
		}

		$days = self::setting( 'auto_archive_days' );
		$days = ( null === $days ) ? null : (int) $days;

		$child_mode = ChildMode::tryFrom( (string) self::setting( 'auto_archive_child_mode' ) ) ?? ChildMode::Open;

		return array(
			new Rule( $this->level(), $days, $child_mode, __( 'Site default', 'archived-post-status' ) ),
		);
	}

	/**
	 * Read one site-level setting through its `aps_{$key}` filter, with the
	 * schema default as the incoming value — the same pair every {@see
	 * \ArchivedPostStatus\Settings\HookAdapter} method resolves against.
	 *
	 * @since 0.5.0
	 * @param string $key The Schema key, e.g. 'auto_archive_enabled'.
	 * @return mixed
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private static function setting( string $key ): mixed {
		return apply_filters( "aps_{$key}", Schema::default_for( $key ) );
	}
}
