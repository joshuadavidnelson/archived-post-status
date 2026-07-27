<?php

namespace ArchivedPostStatus\Archive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Resolves the set of post statuses that may transition into the archive.
 *
 * Holds the body of the retired internal archivable-statuses helper: applies
 * the `aps_archivable_statuses` filter to the default list and returns the
 * sanitised slug array.
 *
 * @since 0.4.0
 */
final class ArchivableStatuses {

	/**
	 * Default list of statuses that may be archived.
	 *
	 * Kept private to the class; consumers route through {@see all()}.
	 *
	 * @var array<int, string>
	 */
	private const DEFAULTS = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Get the statuses that are archivable.
	 *
	 * Replaces the retired internal archivable-statuses helper; all internal
	 * call sites route through this class. Post-status slugs are normalised
	 * via `sanitize_key()`, the canonical filter for slug strings.
	 *
	 * @since 0.4.0
	 * @return string[]
	 */
	public static function all(): array {

		/**
		 * Filter the statuses that are archivable.
		 *
		 * @since 0.4.0
		 * @return string[] The statuses that are archivable.
		 */
		$statuses = (array) apply_filters( 'aps_archivable_statuses', self::DEFAULTS );

		return array_filter( array_map( 'sanitize_key', $statuses ) );
	}

	/**
	 * Whether the given status slug is in the archivable set.
	 *
	 * @since 0.4.0
	 * @param string $status Post status slug.
	 * @return bool
	 */
	public static function includes( string $status ): bool {
		return in_array( $status, self::all(), true );
	}
}
