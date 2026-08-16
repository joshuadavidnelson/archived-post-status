<?php
/**
 * Full Four-Level Cascade — end-to-end integration Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 *
 * The complete network -> site -> term -> post cascade, exercised end to
 * end through {@see RuleChain::default()} -- the same canonical chain
 * {@see \ArchivedPostStatus\Plugin::schedule_hookables()} and
 * {@see \aps_get_auto_archive_rule()} both build (0.5.0 phase 9) -- over
 * REAL provider instances, not fakes. `NetworkRuleProvider` +
 * `SiteRuleProvider`'s own resolution is already covered end to end in
 * `NetworkSiteCascadeTest`, and the term level's §4.2 tie-break in
 * `TermRuleProviderTest`; this file's job is proving the POST level
 * composes correctly at the end of the real four-level chain, per plan
 * §4.1's worked table and the phase-9 brief's explicit requirement: the
 * post override wins when nothing above it freezes, and a `Locked` term
 * rule wins instead when something does.
 *
 * No filter is explicitly registered for RuleChain's own internal hooks
 * (`aps_auto_archive_levels`, `aps_auto_archive_{level}_rule`,
 * `aps_auto_archive_rule_chain`, `aps_auto_archive_resolved_rule`) -- an
 * unregistered WP_Mock filter passes its value through unchanged, exactly
 * like a real, unhooked apply_filters() (see NetworkSiteCascadeTest's own
 * class docblock for the same technique).
 */

use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\AutoArchive\RuleChain;
use ArchivedPostStatus\AutoArchive\TermMeta;
use ArchivedPostStatus\Settings\NetworkStore;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\AutoArchive\RuleChain
 */
class FourLevelCascadeTest extends TestCase {

	public function set_up() {
		parent::set_up();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		NetworkStore::flush_cache();
		parent::tear_down();
	}

	/**
	 * Not a network-activated install -- the network level contributes
	 * nothing, mirroring NetworkSiteCascadeTest's own
	 * `test_not_network_activated_resolves_to_the_site_rule_alone()`.
	 */
	private function stubNotNetworkActivated(): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
	}

	/**
	 * Stub the site-level `aps_*` filter chain SiteRuleProvider reads,
	 * mirroring NetworkSiteCascadeTest's own stubSiteRuleFor().
	 *
	 * @param int    $post_id
	 * @param int    $days       Site auto_archive_days.
	 * @param string $child_mode Site auto_archive_child_mode.
	 */
	private function stubSiteRuleFor( int $post_id, int $days, string $child_mode = 'open' ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( $post_id )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( $days );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( $child_mode );
	}

	/**
	 * Stub a single term in the 'category' taxonomy carrying the given
	 * rule, mirroring TermRuleProviderTest's own fixture shape.
	 *
	 * @param int    $post_id
	 * @param int    $term_id
	 * @param int    $days
	 * @param string $child_mode
	 */
	private function stubTermRuleFor( int $post_id, int $term_id, int $days, string $child_mode = 'open' ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );

		$term = $this->createMockTerm( array( 'term_id' => $term_id, 'name' => 'News', 'taxonomy' => 'category' ) );

		\WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( $post_id, 'category' )
			->andReturn( array( $term ) );

		\WP_Mock::userFunction( 'get_term_meta' )
			->with( $term_id, TermMeta::META_DAYS, true )
			->andReturn( (string) $days );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( $term_id, TermMeta::META_CHILD_MODE, true )
			->andReturn( $child_mode );

		\WP_Mock::userFunction( 'get_taxonomy' )
			->with( 'category' )
			->andReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Category' ) ) );
	}

	/**
	 * Stub the post's own override.
	 *
	 * @param int $post_id
	 * @param int $days
	 */
	private function stubPostOverride( int $post_id, int $days ): void {
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( $post_id, PostRuleProvider::META_DAYS, true )
			->andReturn( (string) $days );
	}

	/**
	 * The post's own override wins when the term above it is Open -- the
	 * most specific level with a value wins, per plan §4.1.
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_post_override_wins_over_an_open_term_rule() {
		$this->stubNotNetworkActivated();
		$this->stubSiteRuleFor( 42, 365, 'open' );
		$this->stubTermRuleFor( 42, 10, 3, 'open' );
		$this->stubPostOverride( 42, 6 );

		$resolved = RuleChain::default()->resolve_for( 42 );

		$this->assertSame( 6, $resolved->days );
		$this->assertSame( 'post', $resolved->origin_level );
		$this->assertSame( 'Post override', $resolved->origin_label );
		$this->assertNull( $resolved->frozen_by, 'Nothing above the post level froze the walk.' );
	}

	/**
	 * A `Locked` term rule freezes the post level below it -- the post's
	 * own override, though present in postmeta and still read by
	 * {@see PostRuleProvider::rules_for()} when the chain assembles, is
	 * never consulted for the resolved outcome, per plan §4.1's "a
	 * non-Open level freezes every level below it, not just the next one".
	 *
	 * @covers ArchivedPostStatus\AutoArchive\RuleChain::resolve_for
	 */
	public function test_a_locked_term_rule_freezes_the_post_level_and_wins_instead() {
		$this->stubNotNetworkActivated();
		$this->stubSiteRuleFor( 42, 365, 'open' );
		$this->stubTermRuleFor( 42, 10, 3, 'locked' );
		$this->stubPostOverride( 42, 6 );

		$resolved = RuleChain::default()->resolve_for( 42 );

		$this->assertSame( 3, $resolved->days, 'The Locked term value wins, not the post override.' );
		$this->assertSame( 'term', $resolved->origin_level );
		$this->assertSame( 'term', $resolved->frozen_by );
	}
}
