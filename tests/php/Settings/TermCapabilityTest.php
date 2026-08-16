<?php
/**
 * Settings\TermCapability Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\TermCapability
 */

use ArchivedPostStatus\Settings\TermCapability;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\TermCapability
 */
class TermCapabilityTest extends TestCase {

	/**
	 * capability() resolves from the taxonomy's OWN registered
	 * `manage_terms` primitive — never a hardcoded `manage_categories` —
	 * for a taxonomy whose cap map sets something other than the default.
	 *
	 * @covers ArchivedPostStatus\Settings\TermCapability::capability
	 */
	public function test_capability_resolves_from_the_taxonomy_s_own_manage_terms_cap() {
		$taxonomy = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_product_terms' ) );

		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'product_cat' )->andReturn( $taxonomy );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_product_terms', 'product_cat' )
			->reply( 'manage_product_terms' );

		$this->assertSame( 'manage_product_terms', TermCapability::capability( 'product_cat' ) );
	}

	/**
	 * An unregistered taxonomy (get_taxonomy() returns false) falls back to
	 * `manage_categories` — core's own default for an unmapped taxonomy
	 * capability — rather than fataling on a null property access.
	 *
	 * @covers ArchivedPostStatus\Settings\TermCapability::capability
	 */
	public function test_capability_falls_back_to_manage_categories_for_an_unregistered_taxonomy() {
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'ghost_tax' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'ghost_tax' )
			->reply( 'manage_categories' );

		$this->assertSame( 'manage_categories', TermCapability::capability( 'ghost_tax' ) );
	}

	/**
	 * A taxonomy object whose cap map has no `manage_terms` entry also falls
	 * back to `manage_categories` rather than emitting an undefined-property
	 * warning.
	 *
	 * @covers ArchivedPostStatus\Settings\TermCapability::capability
	 */
	public function test_capability_falls_back_when_cap_map_has_no_manage_terms_entry() {
		$taxonomy = (object) array( 'cap' => (object) array() );

		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'category' )
			->reply( 'manage_categories' );

		$this->assertSame( 'manage_categories', TermCapability::capability( 'category' ) );
	}

	/**
	 * granted() denies a user lacking the resolved capability — the exact
	 * proof point the phase brief names for TermMetaRegistrar's
	 * auth_callback.
	 *
	 * @covers ArchivedPostStatus\Settings\TermCapability::granted
	 */
	public function test_granted_denies_a_user_lacking_the_taxonomy_s_manage_terms_capability() {
		$taxonomy = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );

		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'category' )
			->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_categories' )
			->andReturn( false );

		$this->assertFalse( TermCapability::granted( 'category' ) );
	}

	/**
	 * granted() allows a user who holds the resolved capability.
	 *
	 * @covers ArchivedPostStatus\Settings\TermCapability::granted
	 */
	public function test_granted_allows_a_user_holding_the_taxonomy_s_manage_terms_capability() {
		$taxonomy = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );

		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'category' )
			->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'manage_categories' )
			->andReturn( true );

		$this->assertTrue( TermCapability::granted( 'category' ) );
	}

	/**
	 * A site can override the resolved capability wholesale via
	 * `aps_default_term_rule_capability`, keyed by taxonomy.
	 *
	 * @covers ArchivedPostStatus\Settings\TermCapability::capability
	 */
	public function test_capability_is_filterable_per_taxonomy() {
		$taxonomy = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );

		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'category' )
			->reply( 'manage_options' );

		$this->assertSame( 'manage_options', TermCapability::capability( 'category' ) );
	}
}
