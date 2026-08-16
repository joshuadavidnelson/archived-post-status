<?php
/**
 * Settings\TermFields Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\TermFields
 */

use ArchivedPostStatus\AutoArchive\TermMeta;
use ArchivedPostStatus\Settings\NetworkStore;
use ArchivedPostStatus\Settings\Store;
use ArchivedPostStatus\Settings\TermFields;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\TermFields
 */
class TermFieldsTest extends TestCase {

	/**
	 * @var TermFields
	 */
	protected $fields;

	public function set_up() {
		parent::set_up();
		$this->fields = new TermFields();
		Store::flush_cache();
		NetworkStore::flush_cache();
	}

	public function tear_down() {
		Store::flush_cache();
		NetworkStore::flush_cache();
		unset( $_POST['_wpnonce_add-tag'], $_POST['_wpnonce'], $_POST['aps_auto_archive_days'], $_POST['aps_auto_archive_child_mode'] );
		parent::tear_down();
	}

	/**
	 * TermInheritance::resolve() reads network activation + the site store;
	 * this pins both to their simplest "unfrozen, empty" state so save()/
	 * render tests below can focus on their own concern.
	 */
	private function stubUnfrozenInheritance(): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn( array( 'auto_archive_enabled' => false ) );
	}

	/**
	 * The same boundary, but with the site level set to Off -- so
	 * TermInheritance::resolve()->frozen() is true.
	 */
	private function stubFrozenInheritance(): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::userFunction( 'get_option' )
			->with( Store::OPTION_KEY, array() )
			->andReturn(
				array(
					'auto_archive_enabled'    => true,
					'auto_archive_child_mode' => 'off',
				)
			);
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * hooks() registers exactly one descriptor: the `wp_loaded` deferral.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::hooks
	 */
	public function test_hooks_registers_exactly_one_wp_loaded_deferral() {
		$descriptors = $this->fields->hooks();

		$this->assertCount( 1, $descriptors );
		$this->assertSame( 'wp_loaded', $descriptors[0]->hook );
		$this->assertTrue( $descriptors[0]->is_action() );
	}

	// -----------------------------------------------------------------------
	// register_taxonomy_hooks() / taxonomy_hooks()
	// -----------------------------------------------------------------------

	/**
	 * Per-taxonomy hooks are registered AFTER taxonomies exist, not at
	 * plugins_loaded -- the exact proof point the phase brief names. Builds
	 * four descriptors (add form, edit form, created, edited) per opted-in,
	 * currently-registered taxonomy.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::register_taxonomy_hooks
	 */
	public function test_register_taxonomy_hooks_builds_four_descriptors_for_each_opted_in_and_registered_taxonomy() {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )
			->with( array( 'category' ) )
			->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'taxonomy_exists' )->with( 'category' )->andReturn( true );

		\WP_Mock::expectActionAdded( 'category_add_form_fields', array( $this->fields, 'render_add_form_fields' ), 10, 1 );
		\WP_Mock::expectActionAdded( 'category_edit_form_fields', array( $this->fields, 'render_edit_form_fields' ), 10, 2 );
		\WP_Mock::expectActionAdded( 'created_category', array( $this->fields, 'save' ), 10, 1 );
		\WP_Mock::expectActionAdded( 'edited_category', array( $this->fields, 'save' ), 10, 1 );

		$this->fields->register_taxonomy_hooks();

		// WP_Mock verifies the expectActionAdded() expectations during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * A configured taxonomy slug that is not (or no longer) registered is
	 * skipped entirely -- no hooks are added for it, since WordPress would
	 * simply never fire them.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::register_taxonomy_hooks
	 */
	public function test_register_taxonomy_hooks_skips_a_configured_but_unregistered_taxonomy() {
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )
			->with( array( 'category' ) )
			->reply( array( 'ghost_tax' ) );
		\WP_Mock::userFunction( 'taxonomy_exists' )->with( 'ghost_tax' )->andReturn( false );
		\WP_Mock::userFunction( 'add_action' )->never();

		$this->fields->register_taxonomy_hooks();

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render_add_form_fields()
	// -----------------------------------------------------------------------

	/**
	 * Nothing renders when the user lacks the taxonomy's own capability.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::render_add_form_fields
	 */
	public function test_render_add_form_fields_renders_nothing_without_capability() {
		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( false );

		ob_start();
		$this->fields->render_add_form_fields( 'category' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A capable user sees the CascadeField output, wrapped in the standard
	 * `{$taxonomy}_add_form_fields` div.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::render_add_form_fields
	 */
	public function test_render_add_form_fields_renders_the_cascade_field_when_capable() {
		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( true );

		$this->stubUnfrozenInheritance();

		ob_start();
		$this->fields->render_add_form_fields( 'category' );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<div class="form-field">', $output );
		$this->assertStringContainsString( 'name="aps_auto_archive_days"', $output );
		$this->assertStringContainsString( 'name="aps_auto_archive_child_mode"', $output );
		// No stored value yet -- the add-new form's own days input starts empty.
		$this->assertStringNotContainsString( 'value="0"', $output );
	}

	// -----------------------------------------------------------------------
	// render_edit_form_fields()
	// -----------------------------------------------------------------------

	/**
	 * Nothing renders when the user lacks the taxonomy's own capability --
	 * resolved from `$term->taxonomy`, not a separately passed value.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::render_edit_form_fields
	 */
	public function test_render_edit_form_fields_renders_nothing_without_capability() {
		$term             = $this->createMockTerm( array( 'term_id' => 10, 'taxonomy' => 'category' ) );
		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( false );

		ob_start();
		$this->fields->render_edit_form_fields( $term, 'category' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * A capable user sees the CascadeField output pre-filled with the
	 * term's own stored value, wrapped in a table row.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::render_edit_form_fields
	 */
	public function test_render_edit_form_fields_renders_the_term_s_own_stored_value() {
		$term             = $this->createMockTerm( array( 'term_id' => 10, 'taxonomy' => 'category' ) );
		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( true );

		\WP_Mock::userFunction( 'get_term_meta' )->with( 10, TermMeta::META_DAYS, true )->andReturn( '6' );
		\WP_Mock::userFunction( 'get_term_meta' )->with( 10, TermMeta::META_CHILD_MODE, true )->andReturn( 'locked' );

		$this->stubUnfrozenInheritance();

		ob_start();
		$this->fields->render_edit_form_fields( $term, 'category' );
		$output = ob_get_clean();

		$this->assertStringContainsString( '<tr class="form-field">', $output );
		$this->assertStringContainsString( 'value="6"', $output );
	}

	// -----------------------------------------------------------------------
	// save()
	// -----------------------------------------------------------------------

	/**
	 * A term_id that no longer resolves to a real term (deleted mid-request)
	 * writes nothing.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_does_nothing_when_the_term_no_longer_exists() {
		\WP_Mock::userFunction( 'get_term' )->with( 10 )->andReturn( false );
		\WP_Mock::userFunction( 'update_term_meta' )->never();
		\WP_Mock::userFunction( 'delete_term_meta' )->never();

		$this->fields->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Neither of WordPress's own two term-form nonces is present in $_POST
	 * (e.g. a REST-driven term creation, or WP-CLI): save() writes nothing.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_does_nothing_when_no_nonce_is_present() {
		$term = $this->createMockTerm( array( 'term_id' => 10, 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'get_term' )->with( 10 )->andReturn( $term );
		\WP_Mock::userFunction( 'update_term_meta' )->never();
		\WP_Mock::userFunction( 'delete_term_meta' )->never();

		$this->fields->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A present but invalid nonce also writes nothing -- this is the CSRF
	 * defense, not merely a presence check.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_does_nothing_when_the_nonce_is_present_but_invalid() {
		$term = $this->createMockTerm( array( 'term_id' => 10, 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'get_term' )->with( 10 )->andReturn( $term );

		$_POST['_wpnonce'] = 'bad-nonce';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'bad-nonce', 'update-tag_10' )->andReturn( false );

		\WP_Mock::userFunction( 'update_term_meta' )->never();
		\WP_Mock::userFunction( 'delete_term_meta' )->never();

		$this->fields->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A valid nonce but a user lacking the taxonomy's own capability still
	 * writes nothing.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_does_nothing_when_capability_is_denied() {
		$term = $this->createMockTerm( array( 'term_id' => 10, 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'get_term' )->with( 10 )->andReturn( $term );

		$_POST['_wpnonce'] = 'good-nonce';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'good-nonce', 'update-tag_10' )->andReturn( 1 );

		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( false );

		\WP_Mock::userFunction( 'update_term_meta' )->never();
		\WP_Mock::userFunction( 'delete_term_meta' )->never();

		$this->fields->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * When an ancestor freezes the cascade, this level's control was never
	 * rendered for the user to submit -- save() must leave whatever is
	 * stored exactly as it is, not wipe it via an empty submission. This is
	 * also the defense against a forged POST body attempting to set a term
	 * override while the ancestor chain is locked or off.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_does_nothing_when_an_ancestor_freezes_the_cascade() {
		$term = $this->createMockTerm( array( 'term_id' => 10, 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'get_term' )->with( 10 )->andReturn( $term );

		$_POST['_wpnonce']                     = 'good-nonce';
		$_POST['aps_auto_archive_days']        = '5';
		$_POST['aps_auto_archive_child_mode']  = 'open';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'good-nonce', 'update-tag_10' )->andReturn( 1 );

		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( true );

		$this->stubFrozenInheritance();

		\WP_Mock::userFunction( 'update_term_meta' )->never();
		\WP_Mock::userFunction( 'delete_term_meta' )->never();

		$this->fields->save( 10 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The full happy path via the add-tag nonce: a valid submission writes
	 * the sanitized days + child_mode to term meta.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_writes_the_sanitized_submission_via_the_add_tag_nonce() {
		$term = $this->createMockTerm( array( 'term_id' => 11, 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'get_term' )->with( 11 )->andReturn( $term );

		$_POST['_wpnonce_add-tag']             = 'good-nonce';
		$_POST['aps_auto_archive_days']        = '5';
		$_POST['aps_auto_archive_child_mode']  = 'locked';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'good-nonce', 'add-tag' )->andReturn( 1 );

		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( true );

		$this->stubUnfrozenInheritance();

		\WP_Mock::userFunction( 'update_term_meta' )->once()->with( 11, TermMeta::META_DAYS, 5 )->andReturn( true );
		\WP_Mock::userFunction( 'update_term_meta' )->once()->with( 11, TermMeta::META_CHILD_MODE, 'locked' )->andReturn( true );

		$this->fields->save( 11 );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A blank submitted days value clears the term's own override -- the
	 * days row is deleted rather than storing an empty string. Uses the
	 * `_wpnonce` (edit-screen) branch, the other half of the nonce dispatch
	 * from the add-tag test above.
	 *
	 * @covers ArchivedPostStatus\Settings\TermFields::save
	 */
	public function test_save_clears_the_term_s_own_override_on_a_blank_submission() {
		$term = $this->createMockTerm( array( 'term_id' => 12, 'taxonomy' => 'category' ) );
		\WP_Mock::userFunction( 'get_term' )->with( 12 )->andReturn( $term );

		$_POST['_wpnonce']                    = 'good-nonce';
		$_POST['aps_auto_archive_days']       = '';
		$_POST['aps_auto_archive_child_mode'] = 'open';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( static fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'good-nonce', 'update-tag_12' )->andReturn( 1 );

		$taxonomy_object = (object) array( 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) );
		\WP_Mock::userFunction( 'get_taxonomy' )->with( 'category' )->andReturn( $taxonomy_object );
		\WP_Mock::onFilter( 'aps_default_term_rule_capability' )->with( 'manage_categories', 'category' )->reply( 'manage_categories' );
		\WP_Mock::userFunction( 'current_user_can' )->with( 'manage_categories' )->andReturn( true );

		$this->stubUnfrozenInheritance();

		\WP_Mock::userFunction( 'delete_term_meta' )->once()->with( 12, TermMeta::META_DAYS )->andReturn( true );
		\WP_Mock::userFunction( 'update_term_meta' )->once()->with( 12, TermMeta::META_CHILD_MODE, 'open' )->andReturn( true );

		$this->fields->save( 12 );

		$this->addToAssertionCount( 1 );
	}
}
