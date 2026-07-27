<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The vocabulary used to identify the archived post status throughout the plugin.
 *
 * Replaces the bare-string literals `'archive'` (slug) and `'Archived'` (label)
 * that previously appeared scattered across the codebase; call sites consume
 * `PostStatusValue::Slug->value` / `PostStatusValue::Label->value` — or
 * {@see self::resolved_slug()} where the `aps_post_status_slug` filter must be
 * honoured — in place of the literals.
 *
 * Deliberately narrow: only the status slug and label live here. Other
 * status-related vocabulary (query var names, option keys, meta keys, hook
 * names) lives with the consuming class.
 *
 * @since 0.4.0
 */
enum PostStatusValue: string {

	/**
	 * The default slug under which the archived post status is registered.
	 *
	 * Site authors can override the slug via the `aps_post_status_slug`
	 * filter; the literal default is centralised here.
	 */
	case Slug = 'archive';

	/**
	 * The default human-readable label for the archived post status.
	 *
	 * Site authors can override the label via the `aps_archived_label_string`
	 * filter; the literal default is centralised here so that translation
	 * call sites continue to feed the canonical English source string.
	 */
	case Label = 'Archived';

	/**
	 * Filterable runtime accessor for the archived post status slug.
	 *
	 * Applies `aps_post_status_slug` once, with `self::Slug->value` as the
	 * default. Every internal consumer that compares against
	 * `$post->post_status` or calls `wp_update_post(['post_status' => ...])`
	 * or `register_post_status()` routes through this method. Stable 0.3.x
	 * applied the filter only at registration — this fixes that leak so
	 * the configured slug is honoured at every internal site.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function resolved_slug(): string {
		return (string) apply_filters( 'aps_post_status_slug', self::Slug->value );
	}
}
