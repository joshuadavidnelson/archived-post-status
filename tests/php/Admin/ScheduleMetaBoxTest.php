<?php
/**
 * Admin\ScheduleMetaBox Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox
 *
 * Covers the observable behaviors of ScheduleMetaBox:
 *   - add_meta_box_maybe() registers only for a schedulable post type, a
 *     user who can archive the post, and when the aps_schedule_panel_enabled
 *     filter has not hidden the control
 *   - render() outputs the nonce field, the resolved-outcome line (from
 *     ScheduleOutcome, so the "and why" origin_label survives to the page),
 *     the datetime-local exact-date field, the CascadeField-rendered
 *     days-override control, and the Clear button
 *   - save() rejects a missing/invalid nonce, an autosave, a revision, a
 *     denied capability, and an unsupported post type; the Clear button
 *     unschedules through the public API (never a raw meta write); setting
 *     an exact date and a days override each write through the public API /
 *     Schema's own sanitizer; an ancestor freeze leaves the days override
 *     untouched
 */

use ArchivedPostStatus\Admin\ScheduleMetaBox;
use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\Schedule\ScheduleMeta;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox
 */
class ScheduleMetaBoxTest extends TestCase {

	/**
	 * @var ScheduleMetaBox
	 */
	protected $meta_box;

	public function set_up() {
		parent::set_up();
		$this->meta_box = new ScheduleMetaBox();
	}

	/**
	 * Stub the post-type opt-in filter to accept the given type (default:
	 * every supported type, since the incoming Schema default is empty).
	 *
	 * @param array<int, string> $configured
	 */
	private function stubSchedulablePostTypes( array $configured = array() ): void {
		\WP_Mock::onFilter( 'aps_scheduled_archive_post_types' )->with( array() )->reply( $configured );
	}

	/**
	 * Stub PostInheritance::resolve()'s full dependency chain to "nothing
	 * inherited, unfrozen" -- the simplest ancestor shape, sufficient for
	 * tests not focused on inheritance itself.
	 *
	 * @param int $post_id
	 */
	private function stubUnfrozenEmptyInheritance( int $post_id ): void {
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( false );
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( $post_id, 'category' )->andReturn( array() );
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::hooks
	 */
	public function test_hooks_registers_add_meta_boxes_and_save_post() {
		$hooks = $this->meta_box->hooks();

		$this->assertCount( 2, $hooks );

		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'add_meta_boxes', $hooks[0]->hook );
		$this->assertSame( 2, $hooks[0]->accepted_args );

		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'save_post', $hooks[1]->hook );
		$this->assertSame( 2, $hooks[1]->accepted_args );
	}

	// -----------------------------------------------------------------------
	// add_meta_box_maybe()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::add_meta_box_maybe
	 */
	public function test_add_meta_box_maybe_registers_the_box_when_eligible() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		$this->stubSchedulablePostTypes();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		\WP_Mock::onFilter( 'aps_schedule_panel_enabled' )->with( true, 42 )->reply( true );

		\WP_Mock::userFunction( 'add_meta_box' )
			->once()
			->with( 'aps-schedule', \Mockery::type( 'string' ), array( $this->meta_box, 'render' ), 'post', 'side', 'default' )
			->andReturn( null );

		$this->meta_box->add_meta_box_maybe( 'post', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::add_meta_box_maybe
	 */
	public function test_add_meta_box_maybe_does_nothing_when_post_is_null() {
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'add_meta_box' )->never();

		$this->meta_box->add_meta_box_maybe( 'post', null );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::add_meta_box_maybe
	 */
	public function test_add_meta_box_maybe_does_nothing_for_a_non_schedulable_post_type() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'attachment' ) );

		$this->stubSchedulablePostTypes( array( 'post' ) );

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'add_meta_box' )->never();

		$this->meta_box->add_meta_box_maybe( 'attachment', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::add_meta_box_maybe
	 */
	public function test_add_meta_box_maybe_does_nothing_when_user_cannot_archive() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		$this->stubSchedulablePostTypes();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'add_meta_box' )->never();

		$this->meta_box->add_meta_box_maybe( 'post', $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::add_meta_box_maybe
	 */
	public function test_add_meta_box_maybe_does_nothing_when_the_panel_filter_disables_it() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		$this->stubSchedulablePostTypes();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		\WP_Mock::onFilter( 'aps_schedule_panel_enabled' )->with( true, 42 )->reply( false );
		\WP_Mock::userFunction( 'add_meta_box' )->never();

		$this->meta_box->add_meta_box_maybe( 'post', $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render()
	// -----------------------------------------------------------------------

	/**
	 * The rendered markup carries the nonce field, the resolved-outcome
	 * line (with the origin label -- the "and why" half), a real
	 * `<label for>` on the datetime-local input associated via
	 * aria-describedby, and a Clear `<button>` (not a link).
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::render
	 */
	public function test_render_outputs_outcome_date_field_cascade_field_and_clear_button() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'wp_nonce_field' )
			->once()->with( 'aps_schedule_meta_box', 'aps_schedule_meta_box_nonce' )->andReturn( '' );

		// ScheduleOutcome::describe(): no schedule record, nothing resolved.
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( '' );
		$this->stubUnfrozenEmptyInheritance( 42 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '' );

		ob_start();
		$this->meta_box->render( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'aps-schedule-outcome', $output );
		$this->assertStringContainsString( 'Not scheduled to archive.', $output );

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $output, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$date_input = $doc->getElementById( 'aps_schedule_date' );
		$this->assertNotNull( $date_input, 'The datetime-local input must be present.' );
		$this->assertSame( 'datetime-local', $date_input->getAttribute( 'type' ) );
		$this->assertSame( 'aps-schedule-outcome', $date_input->getAttribute( 'aria-describedby' ) );

		$labels = $doc->getElementsByTagName( 'label' );
		$found_label_for_date = false;
		foreach ( $labels as $label ) {
			if ( 'aps_schedule_date' === $label->getAttribute( 'for' ) ) {
				$found_label_for_date = true;
			}
		}
		$this->assertTrue( $found_label_for_date, 'A real <label for> must target the date input.' );

		$buttons = $doc->getElementsByTagName( 'button' );
		$this->assertSame( 1, $buttons->length, 'Clear must be a real <button>, not a styled link.' );
		$this->assertSame( 'submit', $buttons->item( 0 )->getAttribute( 'type' ) );
		$this->assertSame( 'aps_schedule_clear', $buttons->item( 0 )->getAttribute( 'name' ) );

		// CascadeField's own markup, proving the days-override control rendered.
		$this->assertStringContainsString( 'aps-cascade-field', $output );
	}

	/**
	 * The date field is pre-filled from an existing MANUAL schedule.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::render
	 */
	public function test_render_prefills_the_date_field_from_an_existing_manual_schedule() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );

		\WP_Mock::userFunction( 'wp_nonce_field' )->andReturn( '' );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );

		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_SOURCE, true )->andReturn( 'manual' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_TIME, true )->andReturn( '1700000000' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_USER, true )->andReturn( '1' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_RULE_VERSION, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, ScheduleMeta::META_ATTEMPTS, true )->andReturn( '0' );
		\WP_Mock::userFunction( 'get_option' )->with( 'date_format' )->andReturn( 'Y-m-d' );
		\WP_Mock::userFunction( 'get_option' )->with( 'time_format' )->andReturn( 'H:i' );
		\WP_Mock::userFunction( 'wp_date' )->andReturnUsing( fn( $format, $ts ) => gmdate( $format, $ts ) );

		$this->stubUnfrozenEmptyInheritance( 42 );
		\WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '' );

		ob_start();
		$this->meta_box->render( $post );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'value="2023-11-14T22:13"', $output );
	}

	// -----------------------------------------------------------------------
	// save() — guards
	// -----------------------------------------------------------------------

	private function stubValidNonce(): void {
		$_POST['aps_schedule_meta_box_nonce'] = 'a-nonce';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'a-nonce', 'aps_schedule_meta_box' )->andReturn( 1 );
	}

	public function tear_down() {
		unset( $_POST['aps_schedule_meta_box_nonce'], $_POST['aps_schedule_clear'], $_POST['aps_schedule_date'], $_POST['aps_schedule_days_override'] );
		parent::tear_down();
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_nothing_when_the_nonce_field_is_missing() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_nothing_when_the_nonce_is_invalid() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		$_POST['aps_schedule_meta_box_nonce'] = 'bad-nonce';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'bad-nonce', 'aps_schedule_meta_box' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_nothing_during_an_autosave() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( 42 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_nothing_for_a_revision() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( 42 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_nothing_when_user_cannot_archive() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();

		// Populate BOTH the exact-date and days-override fields so a
		// bypassed capability gate would actually reach save_exact_date()
		// and save_days_override() — an empty $_POST would let those two
		// methods no-op on their own and mask the gate entirely.
		$_POST['aps_schedule_date']          = '2024-06-15T10:30';
		$_POST['aps_schedule_days_override'] = '6';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_nothing_for_an_unsupported_post_type() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'attachment' ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes( array( 'post' ) );
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() — Clear
	// -----------------------------------------------------------------------

	/**
	 * The Clear button unschedules through the public API and drops the
	 * days override — never a raw meta write for the schedule itself, per
	 * the plan's §5.1 one-path design.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_clear_button_unschedules_through_the_public_api_and_drops_the_override() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_clear'] = '1';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_unschedule_archive' )->once()->with( 42 )->andReturn( true );
		\WP_Mock::userFunction( 'delete_post_meta' )->once()->with( 42, PostRuleProvider::META_DAYS )->andReturn( true );
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() — exact date
	// -----------------------------------------------------------------------

	/**
	 * A well-formed submitted date writes a manual schedule through
	 * {@see \aps_schedule_archive()}.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_sets_a_manual_schedule_from_a_well_formed_date() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_date'] = '2024-06-15T10:30';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();
		$this->stubUnfrozenEmptyInheritance( 42 );

		\WP_Mock::userFunction( 'get_post_meta' )->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '' );
		\WP_Mock::userFunction( 'delete_post_meta' )->with( 42, PostRuleProvider::META_DAYS )->andReturn( true );

		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'aps_schedule_archive' )
			->once()->with( 42, 1718447400, 'manual' )->andReturn( true );

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * An empty date submission is a no-op — clearing is the dedicated Clear
	 * button's job, not an emptied field's.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_does_not_schedule_or_clear_when_the_date_field_is_empty() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();
		$this->stubUnfrozenEmptyInheritance( 42 );
		\WP_Mock::userFunction( 'get_post_meta' )->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '' );
		\WP_Mock::userFunction( 'delete_post_meta' )->with( 42, PostRuleProvider::META_DAYS )->andReturn( true );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Unparseable date input is left untouched rather than guessed at.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_ignores_unparseable_date_input() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_date'] = 'not-a-date';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();
		$this->stubUnfrozenEmptyInheritance( 42 );
		\WP_Mock::userFunction( 'get_post_meta' )->with( 42, PostRuleProvider::META_DAYS, true )->andReturn( '' );
		\WP_Mock::userFunction( 'delete_post_meta' )->with( 42, PostRuleProvider::META_DAYS )->andReturn( true );
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() — days override
	// -----------------------------------------------------------------------

	/**
	 * A submitted days value writes through Schema's own sanitizer.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_sets_the_days_override_when_submitted() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_days_override'] = '6';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();
		$this->stubUnfrozenEmptyInheritance( 42 );

		\WP_Mock::userFunction( 'update_post_meta' )
			->once()->with( 42, PostRuleProvider::META_DAYS, 6 )->andReturn( true );

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * An ancestor freeze leaves the days override untouched -- the control
	 * was never rendered for the user to submit, so a forged POST body
	 * cannot set one either.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleMetaBox::save
	 */
	public function test_save_leaves_the_days_override_untouched_when_an_ancestor_is_frozen() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_days_override'] = '6';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		// Locked site rule -> frozen ancestor chain.
		\WP_Mock::userFunction( 'is_multisite' )->andReturn( false );
		\WP_Mock::onFilter( 'aps_auto_archive_enabled' )->with( false )->reply( true );
		\WP_Mock::onFilter( 'aps_auto_archive_types' )->with( array() )->reply( array( 'post' ) );
		\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'post' );
		\WP_Mock::onFilter( 'aps_auto_archive_days' )->with( null )->reply( 365 );
		\WP_Mock::onFilter( 'aps_auto_archive_child_mode' )->with( 'open' )->reply( 'locked' );
		\WP_Mock::onFilter( 'aps_auto_archive_taxonomies' )->with( array( 'category' ) )->reply( array( 'category' ) );
		\WP_Mock::userFunction( 'wp_get_post_terms' )->with( 42, 'category' )->andReturn( array() );

		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->meta_box->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}
}
