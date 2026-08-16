<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The two term meta keys a taxonomy term's own cascade rule lives in.
 *
 * Mirrors {@see \ArchivedPostStatus\Schedule\ScheduleMeta}'s shape. A term
 * that has never been configured has NEITHER key stored -- {@see for_term()}
 * always returns an instance (there is no "no record" state to distinguish,
 * unlike ScheduleMeta), but `$days` stays `null` and `$child_mode` stays
 * {@see ChildMode::Open} until something is actually written, which is what
 * lets {@see \ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider} tell
 * "this term sets nothing" apart from "this term explicitly sets 0 days" --
 * the same null-vs-zero hazard the site level's `auto_archive_days` already
 * guards against.
 *
 * @since 0.5.0
 */
final class TermMeta {

	/** @var string The term meta key for the term's own days value. */
	public const META_DAYS = '_aps_auto_archive_days';

	/** @var string The term meta key for the term's own child_mode value. */
	public const META_CHILD_MODE = '_aps_auto_archive_child_mode';

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param ?int      $days       This term's own days value; null if the
	 *                              term sets nothing.
	 * @param ChildMode $child_mode This term's own child_mode; Open when
	 *                              nothing is stored.
	 */
	public function __construct(
		public readonly ?int $days,
		public readonly ChildMode $child_mode
	) {}

	/**
	 * Read a term's own rule from stored term meta.
	 *
	 * An empty-string META_DAYS (WordPress's "no such row" return from
	 * `get_term_meta( …, true )`) is `null`, not `0` -- see the class
	 * docblock.
	 *
	 * @since 0.5.0
	 * @param int $term_id The term ID to read.
	 * @return self
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical enum-hydration accessor.
	 */
	public static function for_term( int $term_id ): self {
		$raw_days = get_term_meta( $term_id, self::META_DAYS, true );
		$days     = '' === $raw_days ? null : (int) $raw_days;

		$child_mode = ChildMode::tryFrom( (string) get_term_meta( $term_id, self::META_CHILD_MODE, true ) ) ?? ChildMode::Open;

		return new self( $days, $child_mode );
	}

	/**
	 * Save this rule to term meta.
	 *
	 * `$days` of `null` deletes the row rather than storing an empty string,
	 * so a stale row can never masquerade as a stored `0`.
	 *
	 * @since 0.5.0
	 * @param int $term_id The term ID to save to.
	 * @return void
	 */
	public function save( int $term_id ): void {
		if ( null === $this->days ) {
			delete_term_meta( $term_id, self::META_DAYS );
		}

		if ( null !== $this->days ) {
			update_term_meta( $term_id, self::META_DAYS, $this->days );
		}

		update_term_meta( $term_id, self::META_CHILD_MODE, $this->child_mode->value );
	}

	/**
	 * Delete both term meta keys for a term.
	 *
	 * @since 0.5.0
	 * @param int $term_id The term ID to delete the rule from.
	 * @return void
	 */
	public function delete( int $term_id ): void {
		delete_term_meta( $term_id, self::META_DAYS );
		delete_term_meta( $term_id, self::META_CHILD_MODE );
	}
}
