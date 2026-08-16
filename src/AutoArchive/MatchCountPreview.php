<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The settings screen's safety valve for the plan's risk #2 ("enabling a
 * rule archives a decade of content in one sweep"): how many posts
 * currently match the configured rule, counted BEFORE it ever fires.
 *
 * Deliberately its own small class rather than a method on {@see
 * \ArchivedPostStatus\Settings\CronHealthCheck} — that class is a pure
 * staleness judgment over plain values with no query of its own; this one
 * necessarily runs a `WP_Query` to get a count, which is a different kind of
 * thing entirely.
 *
 * Reuses {@see RuleQuery::candidates()}'s exact args rather than building a
 * second, parallel filter shape — the count a site sees on the settings
 * screen is only trustworthy if it is built from the identical `post_type`
 * / `post_status` / `meta_query` / `date_query` clauses {@see RuleStamper}
 * will actually query against, not an approximation of them. `posts_per_page
 * => 1` (via `$batch_size`) keeps the query cheap: only `found_posts` is
 * read, so WordPress never has to fetch more than one row.
 *
 * @since 0.5.0
 */
final class MatchCountPreview {

	/**
	 * Builds the WP_Query for {@see RuleQuery::candidates()}'s args. Defaults
	 * to a real query; the constructor seam is what keeps {@see count()} a
	 * plain unit test, mirroring
	 * {@see \ArchivedPostStatus\Schedule\Sweeper}'s own `$query_factory` seam.
	 *
	 * @since 0.5.0
	 * @var \Closure
	 */
	private readonly \Closure $query_factory;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param callable|null $query_factory Builds a `WP_Query` from
	 *                                     {@see RuleQuery::candidates()}'s
	 *                                     output, e.g. `fn( array $args ) =>
	 *                                     new \WP_Query( $args )`. Defaults
	 *                                     to exactly that.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- \Closure::fromCallable() is core PHP, not project coupling.
	 */
	public function __construct( ?callable $query_factory = null ) {
		$this->query_factory = null !== $query_factory
			? \Closure::fromCallable( $query_factory )
			: static function ( array $args ): \WP_Query {
				return new \WP_Query( $args );
			};
	}

	/**
	 * How many posts currently match the candidate query for `$min_days`.
	 *
	 * `$min_days` carries the exact same warning as {@see
	 * RuleQuery::candidates()}'s own parameter — this is a preview of that
	 * same query, not an independent judgment.
	 *
	 * @since 0.5.0
	 * @param int $min_days The smallest `days` value any cascade level could
	 *                      currently produce — see {@see RuleQuery}'s class
	 *                      docblock.
	 * @param int $now      Current UTC epoch.
	 * @return int
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical query-builder accessor.
	 */
	public function count( int $min_days, int $now ): int {
		$query = ( $this->query_factory )( RuleQuery::candidates( $now, $min_days, 1 ) );

		return (int) $query->found_posts;
	}
}
