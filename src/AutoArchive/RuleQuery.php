<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchivableStatuses;
use ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Settings\Schema;

/**
 * Builds the WP_Query args for {@see RuleStamper}'s two passes: unstamped
 * candidates, and rule-stamped schedules at a stale rules version.
 *
 * A coarse SQL net, not the resolution itself — {@see RuleStamper} still
 * resolves the cascade per post via {@see RuleChain::resolve_for()} and
 * skips anything that does not actually come out scheduled. This class only
 * answers "which posts are even worth resolving", the same split
 * {@see \ArchivedPostStatus\Schedule\SweepQuery} draws for the sweeper.
 *
 * ⚠️ **`$min_days` is the whole safety of {@see self::candidates()}.** It
 * MUST be the smallest `days` value ANY cascade level could currently
 * produce for ANY post the query might match — never one level's value in
 * isolation. {@see self::min_days()} is where this class computes that
 * number for {@see RuleStamper} to pass in; as of phase 8 it takes the
 * minimum across {@see \ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider},
 * the site level's own settings, and the smallest `days` value stored in
 * TERM META across every opted-in taxonomy — see {@see self::term_min_days()}
 * for the query and the deliberate choices behind it. **Phase 9, which adds
 * the post level, must extend {@see self::min_days()} further**: a post
 * override of 1 day can be smaller than every level already checked here,
 * and the caller computing `$min_days` must take the minimum across every
 * level that could apply, not just the ones already covered. Getting this
 * wrong does not throw or log anything — a post whose effective rule is
 * smaller than the `$min_days` this method was called with is silently
 * invisible to `date_query`, and simply never archives. There is no
 * correctness check inside this class that can catch that mistake; it can
 * only be caught by whoever computes the right number.
 *
 * @since 0.5.0
 */
final class RuleQuery {

	/**
	 * Default number of posts a single stamp/refresh batch processes.
	 *
	 * @since 0.5.0
	 * @var int
	 */
	private const DEFAULT_BATCH_SIZE = 100;

	/**
	 * Query args for posts eligible to be newly stamped by the cascade.
	 *
	 * `META_SOURCE NOT EXISTS` is this query's own manual/exempt exclusion: a
	 * post that already carries ANY source — `manual`, `rule`, or the
	 * `exempt` tombstone — never matches, by construction. {@see RuleStamper}
	 * does not trust this clause alone, though: its candidate pass
	 * independently re-checks for an existing {@see ScheduleMeta} record
	 * before ever writing, the same defense-in-depth its stale-refresh pass
	 * applies against its own query — see that class's docblock.
	 *
	 * The `date_query` cutoff is `$min_days` before `$now`, on the column
	 * `auto_archive_age_basis` names (`post_modified_gmt` for `modified`,
	 * `post_date_gmt` for `published`) — see the class docblock for why
	 * `$min_days` is a required parameter rather than something this method
	 * resolves from settings itself.
	 *
	 * @since 0.5.0
	 * @param int $now        Current UTC epoch.
	 * @param int $min_days   REQUIRED. The smallest `days` value any cascade
	 *                        level could currently produce — see the class
	 *                        docblock's warning.
	 * @param int $batch_size Maximum number of candidates this query returns.
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical archivable-statuses/settings-table accessors.
	 */
	public static function candidates( int $now, int $min_days, int $batch_size ): array {
		$basis  = self::age_basis();
		$column = 'published' === $basis ? 'post_date_gmt' : 'post_modified_gmt';
		$cutoff = $now - ( $min_days * DAY_IN_SECONDS );

		$args = array(
			'post_type'           => self::post_types(),
			'post_status'         => ArchivableStatuses::all(),
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a NOT EXISTS clause on the schedule source is this query's whole purpose; there is no meta-free way to express "never been stamped or scheduled".
				array(
					'key'     => ScheduleMeta::META_SOURCE,
					'compare' => 'NOT EXISTS',
				),
			),
			'date_query'          => array(
				array(
					'column'    => $column,
					'before'    => gmdate( 'Y-m-d H:i:s', $cutoff ),
					'inclusive' => true,
				),
			),
			'orderby'             => 'published' === $basis ? 'date' : 'modified',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'posts_per_page'      => $batch_size,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		return self::filtered( $args, 'candidates', $now );
	}

	/**
	 * Query args for posts already stamped by the cascade at a rules
	 * version other than `$current_version` (plan §4.7).
	 *
	 * No `$min_days` here — this pass is independent of it. A schedule
	 * already exists; the only question is whether its recorded rules
	 * version is current, which {@see RuleStamper} settles per post against
	 * {@see ScheduleMeta::for_post()} even after this query has filtered.
	 *
	 * @since 0.5.0
	 * @param int $now             Current UTC epoch, passed through to the
	 *                             args filter only — this pass's own
	 *                             filtering does not depend on it.
	 * @param int $current_version {@see RulesVersion::current()}'s value at
	 *                             the moment this query is built.
	 * @param int $batch_size      Maximum number of refreshes this query
	 *                             returns.
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical archivable-statuses/settings-table accessors.
	 */
	public static function stale_refreshes( int $now, int $current_version, int $batch_size ): array {
		$args = array(
			'post_type'           => self::post_types(),
			'post_status'         => ArchivableStatuses::all(),
			'meta_query'          => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the stale-version comparison is this query's whole purpose.
				array(
					'key'     => ScheduleMeta::META_SOURCE,
					'value'   => ScheduleSource::Rule->value,
					'compare' => '=',
				),
				array(
					'key'     => ScheduleMeta::META_RULE_VERSION,
					'value'   => $current_version,
					'compare' => '!=',
					'type'    => 'NUMERIC',
				),
			),
			'orderby'             => 'ID',
			'order'               => 'ASC',
			'fields'              => 'ids',
			'posts_per_page'      => $batch_size,
			'no_found_rows'       => false,
			'ignore_sticky_posts' => true,
		);

		return self::filtered( $args, 'stale_refreshes', $now );
	}

	/**
	 * The current `$min_days` — see the class docblock's warning. The
	 * minimum of every level's own contribution, ignoring whichever level
	 * has nothing to contribute; null only when NO level could currently
	 * schedule anything, in which case {@see RuleStamper}'s candidate pass
	 * does not run at all that batch.
	 *
	 * Called once per stamp batch by {@see RuleStamper::process_batch()},
	 * never inside a per-post loop — {@see self::term_min_days()}'s own
	 * query cost depends on this.
	 *
	 * @since 0.5.0
	 * @return ?int
	 */
	public static function min_days(): ?int {
		$candidates = array_filter(
			array( self::network_min_days(), self::site_min_days(), self::term_min_days() ),
			static fn ( ?int $days ): bool => null !== $days
		);

		return array() === $candidates ? null : min( $candidates );
	}

	/**
	 * The site level's own contribution to {@see self::min_days()}. Null
	 * when nothing at the site level could currently schedule anything
	 * (auto-archive disabled, or enabled with no days value set).
	 *
	 * @since 0.5.0
	 * @return ?int
	 */
	private static function site_min_days(): ?int {
		if ( ! (bool) self::setting( 'auto_archive_enabled' ) ) {
			return null;
		}

		$days = self::setting( 'auto_archive_days' );

		return null === $days ? null : (int) $days;
	}

	/**
	 * The network level's own contribution to {@see self::min_days()}.
	 * Delegates entirely to {@see NetworkRuleProvider::rules_for()} rather
	 * than re-deriving its network-activation/enabled/days checks here —
	 * that provider is the single place that logic lives. `$post_id` is
	 * irrelevant to the network level (see that provider's own docblock),
	 * so `0` is passed.
	 *
	 * @since 0.5.0
	 * @return ?int
	 */
	private static function network_min_days(): ?int {
		$rules = ( new NetworkRuleProvider() )->rules_for( 0 );

		return array() === $rules ? null : $rules[0]->days;
	}

	/**
	 * The term level's own contribution to {@see self::min_days()}: the
	 * smallest {@see TermMeta::META_DAYS} stored across every term of every
	 * opted-in taxonomy — a genuine `MIN()` aggregate, not a per-term walk.
	 *
	 * A direct query is required: there is no `WP_Term_Query` equivalent for
	 * "smallest numeric meta value across every term of several taxonomies",
	 * and re-deriving it from {@see \ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider}
	 * would mean loading and reducing every opted-in term on every stamp
	 * batch instead of one indexed aggregate. `wp_termmeta` is indexed on
	 * `meta_key`, so the `MIN()` itself is cheap; the `INNER JOIN` against
	 * `wp_term_taxonomy` is what keeps a term meta row belonging to a
	 * NON-opted-in taxonomy from contributing — a plugin using term meta for
	 * something else entirely must never leak into this number.
	 *
	 * Null when no opted-in taxonomy is configured, or when no term in any
	 * opted-in taxonomy has ever stored a days value.
	 *
	 * @since 0.5.0
	 * @return ?int
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/term-meta accessors.
	 */
	private static function term_min_days(): ?int {
		$taxonomies = self::taxonomies();

		if ( array() === $taxonomies ) {
			return null;
		}

		global $wpdb;

		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- a MIN() aggregate across every opted-in taxonomy's term meta has no WP_Term_Query equivalent; called once per stamp batch (see the class docblock), never per post.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a string of exactly count($taxonomies) "%s" tokens built by this method, never user input; every actual bound value passes through $wpdb->prepare()'s own args below.
		$min = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN( tm.meta_value + 0 ) FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id WHERE tm.meta_key = %s AND tt.taxonomy IN ( {$placeholders} )",
				array_merge( array( TermMeta::META_DAYS ), $taxonomies )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return null === $min ? null : (int) $min;
	}

	/**
	 * The configured `auto_archive_taxonomies` opt-in list, read the same
	 * way {@see \ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider}
	 * reads its own settings.
	 *
	 * @since 0.5.0
	 * @return string[]
	 */
	private static function taxonomies(): array {
		$taxonomies = self::setting( 'auto_archive_taxonomies' );

		return is_array( $taxonomies ) ? $taxonomies : array();
	}

	/**
	 * Resolve the number of posts a single candidate/refresh batch processes.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	public static function batch_size(): int {

		/**
		 * Filters the number of posts a single RuleStamper candidate or
		 * stale-refresh query returns.
		 *
		 * @since 0.5.0
		 * @param int $batch_size Default 100.
		 */
		return (int) apply_filters( 'aps_auto_archive_batch_size', self::DEFAULT_BATCH_SIZE );
	}

	/**
	 * Apply the shared query-args filter to one pass's built args.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $args The query args.
	 * @param string                $which 'candidates' or 'stale_refreshes'.
	 * @param int                   $now   Current UTC epoch.
	 * @return array<string, mixed>
	 */
	private static function filtered( array $args, string $which, int $now ): array {

		/**
		 * Filters RuleStamper's WP_Query args for one of its two passes.
		 *
		 * @since 0.5.0
		 * @param array<string, mixed> $args  The query args.
		 * @param string                $which Which pass built these args:
		 *                                     'candidates' or 'stale_refreshes'.
		 * @param int                   $now   Current UTC epoch used to build them.
		 */
		return (array) apply_filters( 'aps_auto_archive_query_args', $args, $which, $now );
	}

	/**
	 * The configured `auto_archive_types` post types, read the same way
	 * {@see \ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider} does
	 * — through the setting's `aps_*` filter, not {@see
	 * \ArchivedPostStatus\Settings\Store} directly.
	 *
	 * @since 0.5.0
	 * @return string[]
	 */
	private static function post_types(): array {
		$types = self::setting( 'auto_archive_types' );

		return is_array( $types ) ? $types : array();
	}

	/**
	 * The configured `auto_archive_age_basis` value: 'modified' | 'published'.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	private static function age_basis(): string {
		return (string) self::setting( 'auto_archive_age_basis' );
	}

	/**
	 * Read one site-level setting through its `aps_{$key}` filter, with the
	 * schema default as the incoming value.
	 *
	 * @since 0.5.0
	 * @param string $key The Schema key.
	 * @return mixed
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private static function setting( string $key ): mixed {
		return apply_filters( "aps_{$key}", Schema::default_for( $key ) );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
