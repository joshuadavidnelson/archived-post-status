<?php
/**
 * Settings\PostInheritance Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\PostInheritance
 *
 * Exercised through REAL NetworkRuleProvider + SiteRuleProvider +
 * TermRuleProvider reads, over a REAL RuleResolver -- not a hand-built
 * CascadeInheritance, mirroring TermInheritanceTest's own approach. Unlike
 * TermInheritance, this class has real post context, so these tests also
 * pin the post-type and taxonomy opt-in gates each provider applies.
 */

use ArchivedPostStatus\Settings\NetworkStore;
use ArchivedPostStatus\Settings\PostInheritance;
use ArchivedPostStatus\Settings\Store;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\PostInheritance
 */
class PostInheritanceTest extends TestCase {

	public function set_up() {
		parent::set_up();
		Store::flush_cache();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		Store::flush_cache();
		NetworkStore::flush_cache();
		parent::tear_down();
	}

	private function stubNotNetworkActivated(): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
	}

	/**
	 * @param int    $post_id
	 * @param int    $days
	 * @param string $child_mode
	 */
	private function stubSiteRuleFor( int $post_id, int $days, string $child_mode = 'open' ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( $post_id )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( $days );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( $child_mode );
	}

	private function stubNoTermRule( int $post_id ): void {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( $post_id, 'category' )->andReturn( array() );
	}

	/**
	 * Nothing at any ancestor level: an unfrozen, empty inheritance.
	 *
	 * @covers ArchivedPostStatus\Settings\PostInheritance::resolve
	 */
	public function test_nothing_inherited_when_no_ancestor_level_sets_anything() {
		$this->stubNotNetworkActivated();
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( false );
		$this->stubNoTermRule( 42 );

		$inheritance = PostInheritance::resolve( 42 );

		$this->assertNull( $inheritance->days );
		$this->assertNull( $inheritance->origin_label );
		$this->assertFalse( $inheritance->frozen() );
	}

	/**
	 * The site's own Open rule is inherited unfrozen when nothing above or
	 * beside it (network, term) sets anything.
	 *
	 * @covers ArchivedPostStatus\Settings\PostInheritance::resolve
	 */
	public function test_inherits_the_site_rule_alone() {
		$this->stubNotNetworkActivated();
		$this->stubSiteRuleFor( 42, 12, 'open' );
		$this->stubNoTermRule( 42 );

		$inheritance = PostInheritance::resolve( 42 );

		$this->assertSame( 12, $inheritance->days );
		$this->assertSame( 'Site default', $inheritance->origin_label );
		$this->assertFalse( $inheritance->frozen() );
	}

	/**
	 * The term level, being more specific, overrides an Open site value --
	 * the post's own override is deliberately excluded from this
	 * inheritance, which is what makes this the term's contribution rather
	 * than the post's.
	 *
	 * @covers ArchivedPostStatus\Settings\PostInheritance::resolve
	 */
	public function test_an_open_term_rule_overrides_the_site_default() {
		$this->stubNotNetworkActivated();
		$this->stubSiteRuleFor( 42, 12, 'open' );

		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		$term = $this->createMockTerm( array( 'term_id' => 10, 'name' => 'News', 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( 42, 'category' )->andReturn( array( $term ) );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, \ArchivedPostStatus\AutoArchive\TermMeta::META_DAYS, true )->andReturn( '3' );
		\WP_Mock::userFunction( 'get_term_meta' )
			->with( 10, \ArchivedPostStatus\AutoArchive\TermMeta::META_CHILD_MODE, true )->andReturn( 'open' );
		\WP_Mock::userFunction( 'get_taxonomy' )
			->with( 'category' )
			->andReturn( (object) array( 'labels' => (object) array( 'singular_name' => 'Category' ) ) );

		$inheritance = PostInheritance::resolve( 42 );

		$this->assertSame( 3, $inheritance->days );
		$this->assertSame( 'Category: News', $inheritance->origin_label );
		$this->assertFalse( $inheritance->frozen() );
	}

	/**
	 * A Locked site value freezes the post's own control -- the badge shown
	 * carries the site's origin, matching CascadeField's locked-badge shape.
	 *
	 * @covers ArchivedPostStatus\Settings\PostInheritance::resolve
	 */
	public function test_a_locked_site_value_freezes_the_post_level() {
		$this->stubNotNetworkActivated();
		$this->stubSiteRuleFor( 42, 365, 'locked' );
		$this->stubNoTermRule( 42 );

		$inheritance = PostInheritance::resolve( 42 );

		$this->assertSame( 365, $inheritance->days );
		$this->assertSame( 'Site default', $inheritance->origin_label );
		$this->assertTrue( $inheritance->locked );
		$this->assertFalse( $inheritance->off );
		$this->assertSame( 'Site default', $inheritance->frozen_by_label );
	}

	/**
	 * An Off site value hides the post-level control entirely.
	 *
	 * @covers ArchivedPostStatus\Settings\PostInheritance::resolve
	 */
	public function test_an_off_site_value_hides_the_post_level_control() {
		$this->stubNotNetworkActivated();
		$this->stubSiteRuleFor( 42, 180, 'off' );
		$this->stubNoTermRule( 42 );

		$inheritance = PostInheritance::resolve( 42 );

		$this->assertTrue( $inheritance->off );
		$this->assertFalse( $inheritance->locked );
		$this->assertSame( 'Site default', $inheritance->frozen_by_label );
	}

	/**
	 * A Locked network value freezes everything below it, including the
	 * post level, the same as it does for the term level in
	 * TermInheritanceTest's equivalent case.
	 *
	 * @covers ArchivedPostStatus\Settings\PostInheritance::resolve
	 */
	public function test_a_locked_network_value_freezes_the_post_level() {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( true );
		\WP_Mock::userFunction( 'get_site_option' )
			->with( 'active_sitewide_plugins', array() )
			->andReturn( array( ARCHIVED_POST_STATUS_PLUGIN => true ) );
		\WP_Mock::userFunction( 'get_network_option' )
			->with( null, NetworkStore::OPTION_KEY, array() )
			->andReturn(
				array(
					'auto_archive_enabled'    => true,
					'auto_archive_days'       => 365,
					'auto_archive_child_mode' => 'locked',
				)
			);

		// The site and term levels are still consulted (the walk only STOPS
		// at the freeze, it does not skip building the rest of the chain) --
		// SiteRuleProvider/TermRuleProvider read through their own aps_*
		// filters, unlike NetworkRuleProvider's direct NetworkStore read.
		$this->stubSiteRuleFor( 42, 12, 'open' );
		$this->stubNoTermRule( 42 );

		$inheritance = PostInheritance::resolve( 42 );

		$this->assertSame( 365, $inheritance->days );
		$this->assertSame( 'Network default', $inheritance->origin_label );
		$this->assertTrue( $inheritance->locked );
		$this->assertSame( 'Network default', $inheritance->frozen_by_label );
	}
}
