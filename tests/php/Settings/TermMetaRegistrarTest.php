<?php
/**
 * Settings\TermMetaRegistrar Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar
 */

use ArchivedPostStatus\AutoArchive\TermMeta;
use ArchivedPostStatus\Settings\TermMetaRegistrar;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar
 */
class TermMetaRegistrarTest extends TestCase {

	/**
	 * @var TermMetaRegistrar
	 */
	protected $registrar;

	public function set_up() {
		parent::set_up();
		$this->registrar = new TermMetaRegistrar();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * hooks() registers exactly one descriptor: `init`. Unlike TermFields,
	 * there is no `wp_loaded` deferral -- see the class docblock for why
	 * register_term_meta() has no ordering hazard against a later-registered
	 * custom taxonomy.
	 *
	 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar::hooks
	 */
	public function test_hooks_registers_exactly_one_init_action() {
		$descriptors = $this->registrar->hooks();

		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'init', $descriptors[0]->hook );
		$this->assertTrue( $descriptors[0]->is_action() );
	}

	// -----------------------------------------------------------------------
	// register_meta()
	// -----------------------------------------------------------------------

	/**
	 * Both TermMeta keys are registered, with REST enabled, for every
	 * opted-in taxonomy.
	 *
	 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar::register_meta
	 */
	public function test_register_meta_registers_both_keys_for_every_opted_in_taxonomy() {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )
			->with( array( 'category' ) )
			->reply( array( 'category', 'post_tag' ) );

		$registered = array();
		\WP_Mock::userFunction( 'register_term_meta' )
			->andReturnUsing(
				function ( $taxonomy, $meta_key, $args ) use ( &$registered ) {
					$registered[] = array( $taxonomy, $meta_key, $args );
					return true;
				}
			);

		$this->registrar->register_meta();

		$this->assertCount( 4, $registered, 'two keys x two taxonomies' );

		foreach ( $registered as $call ) {
			$this->assertContains( $call[0], array( 'category', 'post_tag' ) );
			$this->assertContains( $call[1], array( TermMeta::META_DAYS, TermMeta::META_CHILD_MODE ) );
			$this->assertTrue( $call[2]['single'] );
			$this->assertTrue( $call[2]['show_in_rest'] );
			$this->assertIsCallable( $call[2]['auth_callback'] );

			$expected_type = TermMeta::META_DAYS === $call[1] ? 'integer' : 'string';
			$this->assertSame( $expected_type, $call[2]['type'] );
		}
	}

	/**
	 * No opted-in taxonomies -- register_term_meta() is never called.
	 *
	 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar::register_meta
	 */
	public function test_register_meta_registers_nothing_with_no_opted_in_taxonomies() {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array() );
		\WP_Mock::userFunction( 'register_term_meta' )->never();

		$this->registrar->register_meta();

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// auth_callback (gated on TermCapability)
	// -----------------------------------------------------------------------

	/**
	 * The auth_callback denies a user lacking the taxonomy's own
	 * `manage_terms` capability -- the exact proof point the phase brief
	 * names.
	 *
	 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar::register_meta
	 */
	public function test_auth_callback_denies_a_user_lacking_the_taxonomy_s_manage_terms_capability() {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )
			->with( array( 'category' ) )
			->reply( array( 'category' ) );

		$captured = array();
		\WP_Mock::userFunction( 'register_term_meta' )
			->andReturnUsing(
				function ( $taxonomy, $meta_key, $args ) use ( &$captured ) {
					if ( TermMeta::META_DAYS === $meta_key ) {
						$captured['auth_callback'] = $args['auth_callback'];
					}
					return true;
				}
			);

		$this->registrar->register_meta();

		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'category' )
			->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( false );

		$this->assertArrayHasKey( 'auth_callback', $captured );
		$this->assertFalse( ( $captured['auth_callback'] )() );
	}

	/**
	 * The positive counterpart: a user holding the capability is granted.
	 *
	 * @covers ArchivedPostStatus\Settings\TermMetaRegistrar::register_meta
	 */
	public function test_auth_callback_allows_a_user_holding_the_taxonomy_s_manage_terms_capability() {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )
			->with( array( 'category' ) )
			->reply( array( 'category' ) );

		$captured = array();
		\WP_Mock::userFunction( 'register_term_meta' )
			->andReturnUsing(
				function ( $taxonomy, $meta_key, $args ) use ( &$captured ) {
					if ( TermMeta::META_DAYS === $meta_key ) {
						$captured['auth_callback'] = $args['auth_callback'];
					}
					return true;
				}
			);

		$this->registrar->register_meta();

		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )
			->with( 'manage_categories', 'category' )
			->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( true );

		$this->assertTrue( ( $captured['auth_callback'] )() );
	}
}
