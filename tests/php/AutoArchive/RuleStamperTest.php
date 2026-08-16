<?php
/**
 * AutoArchive\RuleStamper Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleStamper
 *
 * The injected query_factory closure stands in for a real WP_Query, the same
 * seam {@see \ArchivedPostStatus\Schedule\Sweeper} uses — these are isolated
 * unit tests with no database. `RuleChain` is real (not mocked — it is
 * `final`), fed a small fake `RuleProviderInterface` so the cascade
 * resolution these tests depend on is genuinely exercised, not assumed.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\ResolvedRule;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleChain;
use ArchivedPostStatus\AutoArchive\RuleProviderInterface;
use ArchivedPostStatus\AutoArchive\RuleStamper;
use ArchivedPostStatus\Schedule\Queue\Budget;
use ArchivedPostStatus\Schedule\ScheduleMeta;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleStamper
 */
class RuleStamperTest extends TestCase {

	/**
	 * NetworkStore carries a static in-memory cache -- flush it on both
	 * sides so a network-settings stub in one test can never leak into the
	 * next, symmetric with StoreTest's / NetworkStoreTest's own discipline.
	 */
	public function set_up() {
		parent::set_up();
		\ArchivedPostStatus\Settings\NetworkStore::flush_cache();
	}

	public function tear_down() {
		\ArchivedPostStatus\Settings\NetworkStore::flush_cache();
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// fixtures
	// -----------------------------------------------------------------------

	/**
	 * A RuleChain of one fake site-level provider that returns the same
	 * Rule (or nothing, for null) for every post.
	 *
	 * @param ?Rule $rule The Rule every post resolves to, or null for "this
	 *                    level contributes nothing".
	 */
	private function chainReturning( ?Rule $rule ): RuleChain {
		$provider = new class( $rule ) implements RuleProviderInterface {
			public function __construct( private readonly ?Rule $rule ) {}

			public function level(): string {
				return 'site';
			}

			public function rules_for( int $post_id ): array {
				return null === $this->rule ? array() : array( $this->rule );
			}
		};

		return new RuleChain( array( $provider ) );
	}

	/**
	 * A RuleStamper whose query_factory returns a canned {posts, found_posts}
	 * double for whichever of the two passes it is called for — distinguished
	 * by the query args' meta_query shape (candidates: one NOT EXISTS clause;
	 * stale refreshes: two clauses, the first comparing '=').
	 *
	 * @param RuleChain            $chain     The chain to construct RuleStamper with.
	 * @param array{posts: int[], found_posts: int} $candidates The candidate pass's canned result.
	 * @param array{posts: int[], found_posts: int} $stale      The stale-refresh pass's canned result.
	 */
	private function stamperWithQueryResults( RuleChain $chain, array $candidates, array $stale ): RuleStamper {
		$candidate_query = (object) $candidates;
		$stale_query     = (object) $stale;

		$factory = static function ( array $args ) use ( $candidate_query, $stale_query ) {
			$is_stale = isset( $args['meta_query'][0]['compare'] ) && '=' === $args['meta_query'][0]['compare'];

			return $is_stale ? $stale_query : $candidate_query;
		};

		return new RuleStamper( $chain, $factory );
	}

	/**
	 * A Budget that is never exceeded, regardless of the real clock/memory
	 * usage this process happens to have at call time.
	 */
	private function neverExceededBudget(): Budget {
		return new Budget( time(), 999999, PHP_INT_MAX );
	}

	/**
	 * A Budget that is exceeded from the very first check.
	 */
	private function alwaysExceededBudget(): Budget {
		return new Budget( 0, 0, PHP_INT_MAX );
	}

	/**
	 * Stub the `aps_auto_archive_*` site settings both RuleQuery and
	 * RuleStamper read directly, with Schema's own defaults as each filter's
	 * incoming value — overridable per test.
	 *
	 * `auto_archive_taxonomies` is stubbed separately, replying `[]` by
	 * default (not Schema's own `['category']` default) so
	 * {@see \ArchivedPostStatus\AutoArchive\RuleQuery::min_days()}'s
	 * term-level aggregate short-circuits before ever touching `$wpdb` --
	 * this file's fixtures exercise the network/site interaction, not the
	 * term level, which has its own dedicated coverage in RuleQueryTest.
	 *
	 * @param array<string, mixed> $overrides Key => value overrides.
	 */
	private function stubSiteSettings( array $overrides = array() ): void {
		$defaults = array(
			'auto_archive_enabled'    => false,
			'auto_archive_days'       => null,
			'auto_archive_types'      => array(),
			'auto_archive_age_basis'  => 'modified',
			'auto_archive_grace_days' => 7,
		);

		foreach ( array_merge( $defaults, array_diff_key( $overrides, array( 'auto_archive_taxonomies' => null ) ) ) as $key => $value ) {
			\WP_Mock::onFilter( "aps_{$key}" )->with( $defaults[ $key ] )->reply( $value );
		}

		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )
			->with( array( 'category' ) )
			->reply( $overrides['auto_archive_taxonomies'] ?? array() );
	}

	/**
	 * Stub the network-activation check plus the stored network option
	 * {@see \ArchivedPostStatus\AutoArchive\RuleQuery::min_days()} reads
	 * (via {@see \ArchivedPostStatus\AutoArchive\Provider\NetworkRuleProvider})
	 * directly — not through an `aps_*` filter, since the network level has
	 * no HookAdapter of its own.
	 *
	 * @param array<string, mixed> $stored The stored network option array.
	 */
	private function stubNetworkActivatedWith( array $stored ): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, \ArchivedPostStatus\Settings\NetworkStore::OPTION_KEY, array() )
			->andReturn( $stored );

		// RulesVersion::current() reads the network half of the counter too,
		// once is_multisite() is true.
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, \ArchivedPostStatus\AutoArchive\RulesVersion::NETWORK_OPTION_KEY, 0 )
			->andReturn( 0 );
	}

	/**
	 * Stub ScheduleMeta::for_post()'s one-call short-circuit for a post with
	 * no schedule record at all -- source empty, the rest of the five reads
	 * never happen.
	 *
	 * @param int $post_id
	 */
	private function stubNoExistingSchedule( int $post_id ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_SOURCE, true )->andReturn( '' );
	}

	/**
	 * Stub the five get_post_meta() reads ScheduleMeta::for_post() makes.
	 *
	 * @param int    $post_id
	 * @param string $source
	 * @param int    $rule_version
	 */
	private function stubExistingSchedule( int $post_id, string $source, int $rule_version = 0 ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_SOURCE, true )->andReturn( $source );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_TIME, true )->andReturn( 1000 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_USER, true )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_RULE_VERSION, true )->andReturn( $rule_version );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, ScheduleMeta::META_ATTEMPTS, true )->andReturn( 0 );
	}

	// -----------------------------------------------------------------------
	// queue_name()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::queue_name
	 */
	public function test_queue_name_is_stamp() {
		$stamper = new RuleStamper( $this->chainReturning( null ) );

		$this->assertSame( 'stamp', $stamper->queue_name() );
	}

	// -----------------------------------------------------------------------
	// constructor — default query_factory
	// -----------------------------------------------------------------------

	/**
	 * With no query_factory supplied, process_batch() builds a real
	 * \WP_Query for both passes rather than requiring every caller to know
	 * about the injection seam.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::__construct
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_process_batch_default_query_factory_constructs_a_real_wp_query() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 5,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$stamper = new RuleStamper( $this->chainReturning( null ) );
		$result  = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 0, $result->remaining );
		$this->assertFalse( $result->budget_exhausted );
	}

	// -----------------------------------------------------------------------
	// process_batch() — a matching candidate
	// -----------------------------------------------------------------------

	/**
	 * A candidate whose cascade resolves to a scheduled rule gets stamped
	 * through ScheduleOperation::set() with source=rule and the current
	 * rules version.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_matching_candidate_gets_stamped_with_source_rule_and_current_rules_version() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 4 );

		$chain   = $this->chainReturning( new Rule( 'site', 30, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 101 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$post_modified = time() - ( 10 * DAY_IN_SECONDS );

		$this->stubNoExistingSchedule( 101 );
		$post = $this->createMockPost( array( 'ID' => 101, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 101 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 101 )->andReturn( $post_modified );

		$captured = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return true;
				}
			);

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 0, $result->remaining );
		$this->assertSame( 'rule', $captured[ ScheduleMeta::META_SOURCE ] );
		$this->assertSame( 4, $captured[ ScheduleMeta::META_RULE_VERSION ] );
		$this->assertSame( $post_modified + ( 30 * DAY_IN_SECONDS ), $captured[ ScheduleMeta::META_TIME ] );
	}

	/**
	 * The 'published' age basis reads get_post_time(), not
	 * get_post_modified_time().
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_published_age_basis_uses_get_post_time_not_modified_time() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled'   => true,
				'auto_archive_days'      => 10,
				'auto_archive_types'     => array( 'post' ),
				'auto_archive_age_basis' => 'published',
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$chain   = $this->chainReturning( new Rule( 'site', 10, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 901 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$this->stubNoExistingSchedule( 901 );
		$post = $this->createMockPost( array( 'ID' => 901, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 901 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_time' )->with( 'U', true, 901 )->andReturn( time() - DAY_IN_SECONDS );
		\WP_Mock::userFunction( 'get_post_modified_time' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
	}

	/**
	 * A write ScheduleOperation::set() rejects (here: the post no longer
	 * exists by the time this batch reaches it) counts as failed, and does
	 * NOT drop the post out of the remaining count — it stays due to be
	 * retried on the next run.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_a_failed_write_is_counted_failed_and_not_dropped_from_remaining() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 10,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$chain   = $this->chainReturning( new Rule( 'site', 10, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 701 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$this->stubNoExistingSchedule( 701 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 701 )->andReturn( time() - DAY_IN_SECONDS );
		\WP_Mock::userFunction( 'get_post' )->with( 701 )->andReturn( null );
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 1, $result->failed );
		$this->assertSame( 1, $result->remaining );
	}

	/**
	 * A post already carrying a schedule of ANY source is never stamped by
	 * the candidate pass, even if a misbehaving `aps_auto_archive_query_args`
	 * filter loosened the candidates query enough to return it -- RuleStamper
	 * checks for an existing ScheduleMeta record itself, independent of the
	 * query's own `META_SOURCE NOT EXISTS` clause. The mirror of
	 * test_manual_and_exempt_posts_are_never_touched_by_the_stale_pass, for
	 * the candidate pass instead.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_a_post_with_an_existing_schedule_of_any_source_is_never_touched_by_the_candidate_pass() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$this->stubExistingSchedule( 111, 'manual' );

		$chain   = $this->chainReturning( new Rule( 'site', 30, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 111 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 1, $result->remaining );
	}

	// -----------------------------------------------------------------------
	// process_batch() — the resolved rule is not scheduled
	// -----------------------------------------------------------------------

	/**
	 * A candidate whose cascade resolves to "not scheduled" (no level set a
	 * days value) is left completely untouched: no post lookup, no basis
	 * read, no meta write.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_a_post_whose_resolved_rule_is_not_scheduled_is_left_completely_untouched() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		// The chain's own level sets nothing -- ResolvedRule::is_scheduled() is false.
		$chain   = $this->chainReturning( null );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 202 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$this->stubNoExistingSchedule( 202 );
		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'get_post_modified_time' )->never();

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 1, $result->remaining, 'An unscheduled candidate is not dropped from the queue.' );
	}

	/**
	 * Enabled with no days value configured at the site level: min_days()
	 * has nothing to work with, so the candidate pass does not run this
	 * batch at all.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_candidate_pass_does_not_run_when_enabled_but_no_days_configured() {
		$this->stubSiteSettings( array( 'auto_archive_enabled' => true ) );
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$calls   = array();
		$chain   = $this->chainReturning( null );
		$factory = static function ( array $args ) use ( &$calls ) {
			$calls[] = $args;

			return (object) array( 'posts' => array(), 'found_posts' => 0 );
		};

		$stamper = new RuleStamper( $chain, $factory );
		$result  = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertCount( 1, $calls, 'Only the stale-refresh pass should query -- the candidate pass has no min_days to search with.' );
		$this->assertSame( '!=', $calls[0]['meta_query'][1]['compare'], 'The one call made must be the stale-refresh pass.' );
	}

	// -----------------------------------------------------------------------
	// process_batch() — min_days across network and site (phase 7)
	// -----------------------------------------------------------------------

	/**
	 * Network 30 + site 365: the candidate pass's `$min_days` prefilter must
	 * be built from the SMALLER value, 30 -- not 365. A prefilter built from
	 * 365 would make a post whose effective rule is 30 days invisible to the
	 * candidate query and silently never archive; see RuleQuery's and
	 * RuleStamper's own class docblocks.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_takes_the_minimum_across_network_and_site() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
			)
		);
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 365,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$chain = $this->chainReturning( null );

		$candidate_args = null;
		$factory        = static function ( array $args ) use ( &$candidate_args ) {
			if ( 'NOT EXISTS' === ( $args['meta_query'][0]['compare'] ?? null ) ) {
				$candidate_args = $args;
			}

			return (object) array( 'posts' => array(), 'found_posts' => 0 );
		};

		$before  = time();
		$stamper = new RuleStamper( $chain, $factory );
		$stamper->process_batch( $this->neverExceededBudget() );

		$this->assertNotNull( $candidate_args, 'The candidate pass must run -- both levels supply a min_days.' );

		$expected_cutoff = $before - ( 30 * DAY_IN_SECONDS );
		$actual_cutoff   = strtotime( $candidate_args['date_query'][0]['before'] . ' UTC' );

		$this->assertLessThanOrEqual(
			5,
			abs( $expected_cutoff - $actual_cutoff ),
			'The candidate prefilter must be built from the smaller network value (30 days), not the site value (365).'
		);
	}

	/**
	 * The network level alone, with the site level disabled entirely, still
	 * supplies a min_days and the candidate pass runs -- min_days is not
	 * site-only.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 * @covers ArchivedPostStatus\AutoArchive\RuleQuery::min_days
	 */
	public function test_min_days_runs_the_candidate_pass_from_network_alone_when_site_is_disabled() {
		$this->stubNetworkActivatedWith(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 30,
			)
		);
		$this->stubSiteSettings(); // auto_archive_enabled defaults false.
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$chain = $this->chainReturning( null );

		$calls   = array();
		$factory = static function ( array $args ) use ( &$calls ) {
			$calls[] = $args;

			return (object) array( 'posts' => array(), 'found_posts' => 0 );
		};

		$stamper = new RuleStamper( $chain, $factory );
		$stamper->process_batch( $this->neverExceededBudget() );

		$this->assertCount( 2, $calls, 'Both the candidate and stale-refresh pass must run -- the network level alone already supplies a min_days.' );
	}

	// -----------------------------------------------------------------------
	// process_batch() — the grace floor
	// -----------------------------------------------------------------------

	/**
	 * A five-year-old post under a 365-day rule stamps at now + grace, NOT
	 * at its long-past due date -- the anti-"empty the site" guarantee.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_a_five_year_old_post_under_a_365_day_rule_stamps_in_the_future_via_the_grace_floor() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 365,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );

		$chain   = $this->chainReturning( new Rule( 'site', 365, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 555 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$before = time();

		// Five years ago -- its 365-day due date is deep in the past.
		$post_modified = $before - ( 5 * 365 * DAY_IN_SECONDS );

		$this->stubNoExistingSchedule( 555 );
		$post = $this->createMockPost( array( 'ID' => 555, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 555 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 555 )->andReturn( $post_modified );

		$captured = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return true;
				}
			);

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );

		$stamp_at = $captured[ ScheduleMeta::META_TIME ];

		$this->assertGreaterThan( $before, $stamp_at, 'The stamp must be in the future, never the long-past due date.' );
		// Default grace is 7 days; allow a little slack for real test-run wall-clock drift.
		$this->assertGreaterThanOrEqual( $before + ( 6 * DAY_IN_SECONDS ), $stamp_at );
	}

	// -----------------------------------------------------------------------
	// process_batch() — the days-to-instant filters (plan §5.11)
	// -----------------------------------------------------------------------

	/**
	 * `aps_auto_archive_grace_period` overrides the grace floor per post,
	 * not only via the global `auto_archive_grace_days` setting.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_grace_period_filter_overrides_the_per_post_grace_floor() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 5,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );
		$this->stubNoExistingSchedule( 951 );

		$chain   = $this->chainReturning( new Rule( 'site', 5, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 951 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$before = time();

		// A long-past due date, so the grace floor (not the due date) decides the stamp.
		$post_modified = $before - ( 100 * DAY_IN_SECONDS );

		$post = $this->createMockPost( array( 'ID' => 951, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 951 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 951 )->andReturn( $post_modified );

		// Default grace is 7 days; filter it up to 30 so the floor moves visibly.
		\WP_Mock::onFilter( 'aps_auto_archive_grace_period' )
			->with( 7 * DAY_IN_SECONDS, 951 )
			->reply( 30 * DAY_IN_SECONDS );

		$captured = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return true;
				}
			);

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertGreaterThanOrEqual( $before + ( 29 * DAY_IN_SECONDS ), $captured[ ScheduleMeta::META_TIME ] );
	}

	/**
	 * `aps_auto_archive_basis_timestamp` lets a site substitute a custom
	 * basis instant for the `post_date`/`post_modified` default.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_basis_timestamp_filter_overrides_the_basis_instant() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 10,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );
		$this->stubNoExistingSchedule( 961 );

		$chain   = $this->chainReturning( new Rule( 'site', 10, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 961 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		// A basis so far in the past that, unfiltered, the grace floor (not
		// the due date) would decide the stamp -- proving the filtered basis,
		// not the default, is what the due-date math actually used.
		$default_basis = time() - ( 500 * DAY_IN_SECONDS );
		$custom_basis  = time() + 1000;

		$post = $this->createMockPost( array( 'ID' => 961, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 961 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 961 )->andReturn( $default_basis );

		\WP_Mock::onFilter( 'aps_auto_archive_basis_timestamp' )
			->with( $default_basis, 961 )
			->reply( $custom_basis );

		$captured = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return true;
				}
			);

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertSame( $custom_basis + ( 10 * DAY_IN_SECONDS ), $captured[ ScheduleMeta::META_TIME ] );
	}

	/**
	 * `aps_auto_archive_stamp_time` is the last word on the final stamp
	 * instant, after `max( due, now + grace )` -- it wins even over a
	 * deliberately unusual computed value.
	 *
	 * The third filter argument is a fresh {@see ResolvedRule} RuleChain
	 * constructs with no injection point this test could hold a reference
	 * to in advance, so this uses {@see forceFilterReply()} rather than
	 * `WP_Mock::onFilter()->with()`, which matches objects by identity --
	 * see {@see RuleChainTest}'s class docblock for why.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_stamp_time_filter_overrides_the_final_computed_instant() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 10,
				'auto_archive_types'   => array( 'post' ),
			)
		);
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 1 );
		$this->stubNoExistingSchedule( 971 );

		$chain   = $this->chainReturning( new Rule( 'site', 10, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 971 ), 'found_posts' => 1 ),
			array( 'posts' => array(), 'found_posts' => 0 )
		);

		$post = $this->createMockPost( array( 'ID' => 971, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 971 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 971 )->andReturn( time() - DAY_IN_SECONDS );

		$override = 123456789;
		$seen     = array();
		$this->forceFilterReply(
			'aps_auto_archive_stamp_time',
			function ( $stamp_at, $post_id, $resolved ) use ( $override, &$seen ) {
				$seen[] = array( $stamp_at, $post_id, $resolved );
				return $override;
			}
		);

		$captured = array();
		\WP_Mock::userFunction( 'update_post_meta' )
			->andReturnUsing(
				function ( $post_id, $key, $value ) use ( &$captured ) {
					$captured[ $key ] = $value;
					return true;
				}
			);

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertSame( $override, $captured[ ScheduleMeta::META_TIME ] );
		$this->assertCount( 1, $seen );
		$this->assertSame( 971, $seen[0][1] );
		$this->assertInstanceOf( ResolvedRule::class, $seen[0][2] );
	}

	/**
	 * Registers a filter reply directly against WP_Mock's event manager,
	 * receiving the real apply_filters() arguments -- needed only when a
	 * test cannot hold a reference to an argument WP_Mock::onFilter()->with()
	 * would need to match by identity (a fresh object with no injection
	 * point). Mirrors {@see RuleChainTest::forceFilterReply()}.
	 *
	 * @param string   $tag   The filter hook name.
	 * @param callable $reply Receives the real apply_filters() arguments,
	 *                        returns the value apply_filters() reports.
	 */
	private function forceFilterReply( string $tag, callable $reply ): void {
		$filter = new class( $tag, $reply ) extends \WP_Mock\Filter {
			private $reply;

			public function __construct( string $name, callable $reply ) {
				parent::__construct( $name );
				$this->reply = $reply;
			}

			public function apply( $args ) {
				return ( $this->reply )( ...$args );
			}
		};

		$event_manager_prop = ( new \ReflectionClass( \WP_Mock::class ) )->getProperty( 'event_manager' );
		$event_manager_prop->setAccessible( true );
		$manager = $event_manager_prop->getValue();

		$filters_prop = ( new \ReflectionClass( $manager ) )->getProperty( 'filters' );
		$filters_prop->setAccessible( true );
		$filters         = $filters_prop->getValue( $manager );
		$filters[ $tag ] = $filter;
		$filters_prop->setValue( $manager, $filters );
	}

	// -----------------------------------------------------------------------
	// process_batch() — the stale-refresh pass
	// -----------------------------------------------------------------------

	/**
	 * Manual and exempt schedules are never touched by the stale-refresh
	 * pass, even if a misbehaving `aps_auto_archive_query_args` filter
	 * loosened the query enough to return them -- RuleStamper checks source
	 * itself, independent of the query.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_manual_and_exempt_posts_are_never_touched_by_the_stale_pass() {
		$this->stubSiteSettings();
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 5 );

		$this->stubExistingSchedule( 301, 'manual', 5 );
		$this->stubExistingSchedule( 302, 'exempt', 5 );

		$chain   = $this->chainReturning( new Rule( 'site', 30, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array(), 'found_posts' => 0 ),
			array( 'posts' => array( 301, 302 ), 'found_posts' => 2 )
		);

		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 2, $result->remaining );
	}

	/**
	 * Source, not version, is what protects a manual date or an exempt
	 * tombstone from the stale-refresh pass.
	 *
	 * Both posts here sit at a STALE version, so `should_refresh()` would
	 * return true for either of them: the source check is the only thing
	 * left that can decline them. The sibling test above stubs matching
	 * versions instead, which means the version check short-circuits first
	 * and the source guard could be deleted entirely without it noticing —
	 * a post an editor scheduled by hand, or deliberately exempted, would
	 * then be silently overwritten by the cascade on the next nightly run.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_a_stale_version_manual_or_exempt_post_is_still_never_refreshed() {
		$this->stubSiteSettings();
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 5 );

		// Version 1 against a current version of 5 — genuinely stale.
		$this->stubExistingSchedule( 311, 'manual', 1 );
		$this->stubExistingSchedule( 312, 'exempt', 1 );

		$chain   = $this->chainReturning( new Rule( 'site', 30, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array(), 'found_posts' => 0 ),
			array( 'posts' => array( 311, 312 ), 'found_posts' => 2 )
		);

		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 2, $result->remaining );
	}

	/**
	 * A stale-version stamp is refreshed; a current-version one, returned
	 * by the same (fake) query, is left alone.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_a_stale_version_stamp_is_refreshed_a_current_version_one_is_not() {
		$this->stubSiteSettings();
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 5 );

		$this->stubExistingSchedule( 601, 'rule', 2 );
		$this->stubExistingSchedule( 602, 'rule', 5 );

		$chain   = $this->chainReturning( new Rule( 'site', 30, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array(), 'found_posts' => 0 ),
			array( 'posts' => array( 601, 602 ), 'found_posts' => 2 )
		);

		$post = $this->createMockPost( array( 'ID' => 601, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 601 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_post' )->with( 602 )->never();
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 601 )->andReturn( time() - DAY_IN_SECONDS );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertSame( 1, $result->remaining, 'The current-version stamp stays -- it was never touched.' );
	}

	/**
	 * `aps_auto_archive_should_refresh` can force a refresh even at the
	 * current version -- the default is only a default.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_should_refresh_filter_can_force_a_refresh_at_the_current_version() {
		$this->stubSiteSettings();
		\WP_Mock::userFunction( 'get_option' )->with( 'aps_rules_version', 0 )->andReturn( 5 );

		$this->stubExistingSchedule( 801, 'rule', 5 );

		\WP_Mock::onFilter( 'aps_auto_archive_should_refresh' )
			->with( false, 801, 5, 5 )
			->reply( true );

		$chain   = $this->chainReturning( new Rule( 'site', 10, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array(), 'found_posts' => 0 ),
			array( 'posts' => array( 801 ), 'found_posts' => 1 )
		);

		$post = $this->createMockPost( array( 'ID' => 801, 'post_type' => 'post' ) );
		\WP_Mock::userFunction( 'get_post' )->with( 801 )->andReturn( $post );
		\WP_Mock::userFunction( 'get_current_user_id' )->andReturn( 0 );
		\WP_Mock::userFunction( 'get_post_modified_time' )->with( 'U', true, 801 )->andReturn( time() - DAY_IN_SECONDS );
		\WP_Mock::userFunction( 'update_post_meta' )->andReturn( true );

		$result = $stamper->process_batch( $this->neverExceededBudget() );

		$this->assertSame( 1, $result->processed );
	}

	// -----------------------------------------------------------------------
	// process_batch() — the budget
	// -----------------------------------------------------------------------

	/**
	 * An exhausted budget stops the loop on the very first item, and the
	 * stale-refresh pass does not run at all this batch -- every post
	 * reached (or not yet reached) stays unstamped.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleStamper::process_batch
	 */
	public function test_an_exhausted_budget_stops_the_loop_and_leaves_posts_unstamped() {
		$this->stubSiteSettings(
			array(
				'auto_archive_enabled' => true,
				'auto_archive_days'    => 10,
				'auto_archive_types'   => array( 'post' ),
			)
		);

		$chain   = $this->chainReturning( new Rule( 'site', 10, ChildMode::Open, 'Site default' ) );
		$stamper = $this->stamperWithQueryResults(
			$chain,
			array( 'posts' => array( 401, 402 ), 'found_posts' => 2 ),
			array( 'posts' => array( 501 ), 'found_posts' => 1 )
		);

		\WP_Mock::userFunction( 'get_post' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'get_post_meta' )->never();

		$result = $stamper->process_batch( $this->alwaysExceededBudget() );

		$this->assertSame( 0, $result->processed );
		$this->assertSame( 0, $result->failed );
		$this->assertTrue( $result->budget_exhausted );
		$this->assertSame( 2, $result->remaining, 'Only the candidate pass ran; its 2 posts stay unstamped and the stale pass never started.' );
	}
}
