<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Schedule\Queue\BatchProcessorInterface;
use ArchivedPostStatus\Schedule\Queue\BatchResult;
use ArchivedPostStatus\Schedule\Queue\Budget;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleOperation;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Settings\Schema;

/**
 * Turns cascade rule matches into scheduled archives, one bounded batch at a
 * time — the auto-archive twin of {@see \ArchivedPostStatus\Schedule\Sweeper}.
 *
 * Two passes per batch, both budget-bounded the same way {@see Sweeper}'s
 * single pass is: **candidates** (posts with no schedule record at all that
 * the cascade might now match) and **stale refreshes** (posts already
 * stamped by a rule whose recorded {@see RulesVersion} is out of date, per
 * the plan's §4.7). Every post either pass reaches goes through the same
 * five-step decision: resolve the cascade via the injected {@see RuleChain},
 * skip outright if the resolved rule is not scheduled, compute the basis
 * instant, compute the stamp instant via {@see DueDate::stamp_at()}, and
 * write through {@see ScheduleOperation::set()} — never a raw meta write,
 * so the schedule hooks fire and there is exactly one write path for a
 * schedule in this plugin, per the plan's §5.1.
 *
 * ⚠️ **The candidate pass's coarse `date_query` net is sized by `$min_days`
 * — see {@see RuleQuery}'s class docblock, which owns computing it via
 * {@see RuleQuery::min_days()}.** Living there rather than here is
 * deliberate: `RuleStamper` already depends on `RuleQuery` for both
 * passes' query args, so resolving `$min_days` there too adds no
 * additional coupling to the network or term level's own classes. As of
 * phase 8, `$min_days` covers network, site, AND term meta across the
 * opted-in taxonomies. **Phase 9 must extend `RuleQuery::min_days()`
 * further** to also cover any post-level override, or a post whose
 * effective rule is smaller than every level already checked there
 * becomes invisible to the candidate query and silently never archives.
 *
 * Neither pass trusts its query's filtering alone, and both re-check the
 * same way: the stale-refresh pass independently reads each post's existing
 * {@see ScheduleMeta} and refuses to touch anything whose `source` is not
 * {@see ScheduleSource::Rule}; the candidate pass independently refuses to
 * touch anything that already has a {@see ScheduleMeta} record at all,
 * regardless of source. An editor's manual date, and an editor's deliberate
 * exemption, both outrank any rule, and that guarantee must hold even if a
 * future `aps_auto_archive_query_args` filter loosens either query.
 *
 * @since 0.5.0
 */
final class RuleStamper implements BatchProcessorInterface {

	/**
	 * The queue name this processor reports to
	 * {@see BatchProcessorInterface::queue_name()}.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const QUEUE_NAME = 'stamp';

	/**
	 * Builds the WP_Query for a pass's query args. Defaults to a real query;
	 * the constructor seam is what keeps {@see process_batch()} a plain unit
	 * test, mirroring {@see \ArchivedPostStatus\Schedule\Sweeper}'s own
	 * `$query_factory` seam.
	 *
	 * @since 0.5.0
	 * @var \Closure
	 */
	private readonly \Closure $query_factory;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param RuleChain     $chain         Resolves the cascade for one post.
	 * @param callable|null $query_factory Builds a pass's WP_Query from
	 *                                     {@see RuleQuery}'s output, e.g.
	 *                                     `fn( array $args ) => new
	 *                                     \WP_Query( $args )`. Defaults to
	 *                                     exactly that.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- \Closure::fromCallable() is core PHP, not project coupling.
	 */
	public function __construct( private readonly RuleChain $chain, ?callable $query_factory = null ) {
		$this->query_factory = null !== $query_factory
			? \Closure::fromCallable( $query_factory )
			: static function ( array $args ): \WP_Query {
				return new \WP_Query( $args );
			};
	}

	/**
	 * @since 0.5.0
	 * @return string
	 */
	public function queue_name(): string {
		return self::QUEUE_NAME;
	}

	/**
	 * Process one bounded chunk of the stamp queue: candidates, then stale
	 * refreshes. The budget is checked before every post in both passes; the
	 * moment it reports exhausted, the current pass stops and the other pass
	 * (if not yet started) does not run at all this batch.
	 *
	 * @since 0.5.0
	 * @param Budget $budget The time and memory ceiling this run may spend.
	 * @return BatchResult
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical query-builder/value-object accessors.
	 */
	public function process_batch( Budget $budget ): BatchResult {
		$now           = time();
		$batch_size    = RuleQuery::batch_size();
		$grace_seconds = $this->grace_seconds();
		$min_days      = RuleQuery::min_days();

		$processed        = 0;
		$failed           = 0;
		$remaining        = 0;
		$budget_exhausted = false;

		if ( null !== $min_days ) {
			[ $processed, $failed, $remaining, $budget_exhausted ] = $this->run_pass(
				RuleQuery::candidates( $now, $min_days, $batch_size ),
				$budget,
				$now,
				$grace_seconds,
				false
			);
		}

		if ( ! $budget_exhausted ) {
			[ $stale_processed, $stale_failed, $stale_remaining, $budget_exhausted ] = $this->run_pass(
				RuleQuery::stale_refreshes( $now, RulesVersion::current(), $batch_size ),
				$budget,
				$now,
				$grace_seconds,
				true
			);

			$processed += $stale_processed;
			$failed    += $stale_failed;
			$remaining += $stale_remaining;
		}

		return new BatchResult( $processed, $failed, $remaining, $budget_exhausted );
	}

	/**
	 * Run one query's worth of posts through the shared stamp/refresh
	 * decision, honoring the budget before every item.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $query_args    A pass's {@see RuleQuery}-built args.
	 * @param Budget                $budget        The run's budget.
	 * @param int                   $now           Current UTC epoch.
	 * @param int                   $grace_seconds Grace floor, in seconds.
	 * @param bool                  $is_stale      Whether this is the
	 *                                             stale-refresh pass —
	 *                                             selects which of
	 *                                             {@see eligible()}'s two
	 *                                             independent re-checks
	 *                                             applies.
	 * @return array{0:int, 1:int, 2:int, 3:bool} [processed, failed, remaining, budget_exhausted]
	 */
	private function run_pass( array $query_args, Budget $budget, int $now, int $grace_seconds, bool $is_stale ): array {
		$query = ( $this->query_factory )( $query_args );

		$ids         = array_map( 'absint', (array) $query->posts );
		$found_posts = (int) $query->found_posts;

		$processed        = 0;
		$failed           = 0;
		$dropped          = 0;
		$budget_exhausted = false;

		foreach ( $ids as $post_id ) {
			if ( $budget->exceeded( time(), memory_get_usage( true ) ) ) {
				$budget_exhausted = true;
				break;
			}

			if ( ! $this->eligible( $post_id, $is_stale ) ) {
				continue;
			}

			$outcome = $this->stamp_post( $post_id, $now, $grace_seconds );

			if ( true === $outcome ) {
				++$processed;
				++$dropped;
			} elseif ( false === $outcome ) {
				++$failed;
			}
			// null: the resolved rule is not scheduled. Left untouched,
			// still matches next run — see the class docblock.
		}

		return array( $processed, $failed, max( 0, $found_posts - $dropped ), $budget_exhausted );
	}

	/**
	 * Resolve the cascade for one post and write its schedule if scheduled.
	 *
	 * @since 0.5.0
	 * @param int $post_id      The post ID.
	 * @param int $now          Current UTC epoch.
	 * @param int $grace_seconds Grace floor, in seconds.
	 * @return ?bool True on a successful write, false on a failed write,
	 *               null if the resolved rule is not scheduled — nothing
	 *               was attempted.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical resolver/query-builder/value-object accessors.
	 */
	private function stamp_post( int $post_id, int $now, int $grace_seconds ): ?bool {
		$resolved = $this->chain->resolve_for( $post_id );

		if ( ! $resolved->is_scheduled() ) {
			return null;
		}

		$basis    = $this->basis_timestamp( $post_id );
		$grace    = $this->grace_period( $post_id, $grace_seconds );
		$stamp_at = DueDate::stamp_at( $basis, (int) $resolved->days, $now, $grace );
		$stamp_at = $this->filtered_stamp_time( $stamp_at, $post_id, $resolved );

		return ScheduleOperation::set( $post_id, $stamp_at, ScheduleSource::Rule, RulesVersion::current() );
	}

	/**
	 * Dispatches to whichever of this pass's two independent eligibility
	 * re-checks applies — see the class docblock. Neither trusts the query
	 * that produced $post_id; both re-read {@see ScheduleMeta} directly.
	 *
	 * @since 0.5.0
	 * @param int  $post_id  The post ID.
	 * @param bool $is_stale Whether this is the stale-refresh pass.
	 * @return bool
	 */
	private function eligible( int $post_id, bool $is_stale ): bool {
		return $is_stale ? $this->eligible_for_refresh( $post_id ) : $this->eligible_as_candidate( $post_id );
	}

	/**
	 * Whether a stale-refresh candidate is actually eligible to be
	 * refreshed: its stored schedule must exist, be rule-sourced (never
	 * manual or exempt — see the class docblock), and either be at a stale
	 * version or have `aps_auto_archive_should_refresh` say so anyway.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object accessor.
	 */
	private function eligible_for_refresh( int $post_id ): bool {
		$existing = ScheduleMeta::for_post( $post_id );

		if ( null === $existing || ScheduleSource::Rule !== $existing->source ) {
			return false;
		}

		return $this->should_refresh( $post_id, $existing->rule_version );
	}

	/**
	 * Whether a fresh candidate is actually eligible to be stamped: it must
	 * have no existing {@see ScheduleMeta} record at all, of any source —
	 * see the class docblock. This is the candidate pass's twin of
	 * {@see eligible_for_refresh()}, checked independently of
	 * {@see RuleQuery::candidates()}'s own `META_SOURCE NOT EXISTS` clause.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object accessor.
	 */
	private function eligible_as_candidate( int $post_id ): bool {
		return null === ScheduleMeta::for_post( $post_id );
	}

	/**
	 * @since 0.5.0
	 * @param int $post_id       The post ID.
	 * @param int $stored_version The rules version recorded on the existing stamp.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical rules-version accessor.
	 */
	private function should_refresh( int $post_id, int $stored_version ): bool {
		$current = RulesVersion::current();
		$default = $stored_version !== $current;

		/**
		 * Filters whether a rule-stamped schedule at a stale rules version
		 * actually gets refreshed.
		 *
		 * @since 0.5.0
		 * @param bool $should_refresh  Whether to refresh. Default true when
		 *                              $stored_version !== $current_version.
		 * @param int  $post_id         The post ID.
		 * @param int  $stored_version  The version recorded on the existing stamp.
		 * @param int  $current_version RulesVersion::current() at the moment of this check.
		 */
		return (bool) apply_filters( 'aps_auto_archive_should_refresh', $default, $post_id, $stored_version, $current );
	}

	/**
	 * The basis instant for one post, per the configured
	 * `auto_archive_age_basis` setting, as a UTC epoch.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return int
	 */
	private function basis_timestamp( int $post_id ): int {
		$default = 'published' === (string) $this->setting( 'auto_archive_age_basis' )
			? (int) get_post_time( 'U', true, $post_id )
			: (int) get_post_modified_time( 'U', true, $post_id );

		/**
		 * Filters the basis instant {@see DueDate::stamp_at()} counts
		 * `days` from for one post — lets a site substitute a custom field
		 * for `post_date`/`post_modified`.
		 *
		 * @since 0.5.0
		 * @param int $basis_timestamp The basis instant, as a UTC epoch.
		 *                             Default `post_date`/`post_modified`
		 *                             per `auto_archive_age_basis`.
		 * @param int $post_id         The post ID.
		 */
		return (int) apply_filters( 'aps_auto_archive_basis_timestamp', $default, $post_id );
	}

	/**
	 * The site-level grace floor, in seconds, from the configured
	 * `auto_archive_grace_days`. Computed once per batch; {@see
	 * grace_period()} is where it becomes filterable per post.
	 *
	 * @since 0.5.0
	 * @return int
	 */
	private function grace_seconds(): int {
		return (int) $this->setting( 'auto_archive_grace_days' ) * DAY_IN_SECONDS;
	}

	/**
	 * The grace floor actually applied to one post — the site default
	 * unless a site filters it per post.
	 *
	 * @since 0.5.0
	 * @param int $post_id       The post ID.
	 * @param int $default_grace The batch's site-level grace floor, in
	 *                           seconds, from {@see grace_seconds()}.
	 * @return int
	 */
	private function grace_period( int $post_id, int $default_grace ): int {

		/**
		 * Filters the grace floor applied to one post, in seconds — the
		 * minimum lead time from now before the stamped instant may occur.
		 * Lets a site vary the backlog floor per post rather than only
		 * globally via `auto_archive_grace_days`.
		 *
		 * @since 0.5.0
		 * @param int $grace_seconds Grace floor, in seconds. Default the
		 *                           site's `auto_archive_grace_days` setting.
		 * @param int $post_id       The post ID.
		 */
		return (int) apply_filters( 'aps_auto_archive_grace_period', $default_grace, $post_id );
	}

	/**
	 * The final stamp instant actually written — the computed
	 * `max( due, now + grace )` value unless a site filters it.
	 *
	 * @since 0.5.0
	 * @param int         $stamp_at The computed stamp instant, as a UTC epoch.
	 * @param int         $post_id  The post ID.
	 * @param ResolvedRule $resolved The resolved cascade this instant came from.
	 * @return int
	 */
	private function filtered_stamp_time( int $stamp_at, int $post_id, ResolvedRule $resolved ): int {

		/**
		 * Filters the final stamp instant a post is due to archive at,
		 * after {@see DueDate::stamp_at()}'s `max( due, now + grace )`.
		 *
		 * @since 0.5.0
		 * @param int          $stamp_at The computed stamp instant, as a UTC epoch.
		 * @param int          $post_id  The post ID.
		 * @param ResolvedRule $resolved The resolved cascade this instant came from.
		 */
		return (int) apply_filters( 'aps_auto_archive_stamp_time', $stamp_at, $post_id, $resolved );
	}

	/**
	 * Read one site-level setting through its `aps_{$key}` filter, with the
	 * schema default as the incoming value — the same pair {@see
	 * \ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider} resolves
	 * against.
	 *
	 * @since 0.5.0
	 * @param string $key The Schema key.
	 * @return mixed
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private function setting( string $key ): mixed {
		return apply_filters( "aps_{$key}", Schema::default_for( $key ) );
	}
}
