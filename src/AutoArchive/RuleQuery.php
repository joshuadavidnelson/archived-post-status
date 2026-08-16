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
 * number for {@see RuleStamper} to pass in; as of phase 7 it takes the
 * minimum across {@see \ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider}
 * and the site level's own settings. **Phases 8 and 9, which add the term
 * and post levels, must extend {@see self::min_days()} further**: a term
 * rule of 3 days or a post override of 1 day can each be smaller than either
 * level phase 7 already checks, and the caller computing `$min_days` must
 * take the minimum across every level that could apply, not just the ones
 * already covered. Getting this wrong does not throw or log anything — a
 * post whose effective rule is smaller than the `$min_days` this method was
 * called with is silently invisible to `date_query`, and simply never
 * archives. There is no correctness check inside this class that can catch
 * that mistake; it can only be caught by whoever computes the right number.
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
	 * @since 0.5.0
	 * @return ?int
	 */
	public static function min_days(): ?int {
		$candidates = array_filter(
			array( self::network_min_days(), self::site_min_days() ),
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
