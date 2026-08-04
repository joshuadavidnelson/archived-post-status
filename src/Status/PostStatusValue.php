<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The archived post status slug.
 *
 * Only the slug lives here. The label has its own accessor,
 * {@see ArchiveLabel::value()}, because every label consumer also needs the
 * `aps_archived_label_string` filter applied.
 *
 * @since 0.4.0
 */
enum PostStatusValue: string {

	/**
	 * The default slug under which the archived post status is registered.
	 *
	 * Overridable via the `aps_post_status_slug` filter.
	 */
	case Slug = 'archive';

	/**
	 * Filterable runtime accessor for the archived post status slug.
	 *
	 * Every internal consumer routes through this. 0.3.x applied
	 * `aps_post_status_slug` only at registration, so a configured slug was
	 * ignored everywhere else.
	 *
	 * An empty filter return falls back to the default — matching 0.3.12
	 * semantics, and without it a filter returning `''` would register a
	 * broken status that no internal comparison could ever match.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public static function resolved_slug(): string {
		$slug = (string) apply_filters( 'aps_post_status_slug', self::Slug->value );

		return empty( $slug ) ? self::Slug->value : $slug;
	}
}
