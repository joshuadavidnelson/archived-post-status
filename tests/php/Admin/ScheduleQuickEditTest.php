<?php
/**
 * Admin\ScheduleQuickEdit Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit
 *
 * Covers the observable behaviors of ScheduleQuickEdit:
 *   - hooks() registers admin_enqueue_scripts, quick_edit_custom_box, and
 *     save_post
 *   - enqueue_scripts() enqueues the row-hydration script on edit.php for a
 *     schedulable post type only
 *   - render() emits the nonce field, the datetime-local date field, and the
 *     Clear checkbox, gated on the Scheduled column and a schedulable post
 *     type
 *   - save() rejects a missing/invalid nonce, an autosave, a revision, a
 *     denied capability, and an unsupported post type; the Clear checkbox
 *     unschedules through the public API and takes precedence over a
 *     submitted date; a well-formed date sets a manual schedule through the
 *     public API; an empty or unparseable date is a no-op
 */

use ArchivedPostStatus\Admin\ScheduleQuickEdit;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit
 */
class ScheduleQuickEditTest extends TestCase {

	/**
	 * @var ScheduleQuickEdit
	 */
	protected $quick_edit;

	public function set_up() {
		parent::set_up();
		$this->quick_edit = new ScheduleQuickEdit();
	}

	public function tear_down() {
		unset(
			$_POST['aps_schedule_quick_edit_nonce'],
			$_POST['aps_schedule_quick_clear'],
			$_POST['aps_schedule_quick_date'],
			$_GET['post_type']
		);
		parent::tear_down();
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

	private function stubValidNonce(): void {
		$_POST['aps_schedule_quick_edit_nonce'] = 'a-nonce';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'a-nonce', 'aps_schedule_quick_edit' )->andReturn( 1 );
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::hooks
	 */
	public function test_hooks_registers_enqueue_quick_edit_box_and_save_post() {
		$hooks = $this->quick_edit->hooks();

		$this->assertCount( 3, $hooks );

		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'admin_enqueue_scripts', $hooks[0]->hook );

		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'quick_edit_custom_box', $hooks[1]->hook );
		$this->assertSame( 2, $hooks[1]->accepted_args );

		$this->assertSame( 'action', $hooks[2]->type );
		$this->assertSame( 'save_post', $hooks[2]->hook );
		$this->assertSame( 2, $hooks[2]->accepted_args );
	}

	// -----------------------------------------------------------------------
	// enqueue_scripts()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::enqueue_scripts
	 */
	public function test_enqueue_scripts_enqueues_on_edit_php_for_a_schedulable_post_type() {
		global $post_type;
		$post_type = 'post';

		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'wp_enqueue_script' )
			->once()
			->with(
				'aps-schedule-inline-edit',
				\Mockery::type( 'string' ),
				array(),
				\Mockery::type( 'string' ),
				true
			);

		$this->quick_edit->enqueue_scripts( 'edit.php' );

		$this->addToAssertionCount( 1 );
		$post_type = null;
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::enqueue_scripts
	 */
	public function test_enqueue_scripts_does_nothing_off_the_list_screen() {
		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		$this->quick_edit->enqueue_scripts( 'post.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::enqueue_scripts
	 */
	public function test_enqueue_scripts_does_nothing_for_an_unsupported_post_type() {
		global $post_type;
		$post_type = 'attachment';

		$this->stubSchedulablePostTypes( array( 'post' ) );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->never();

		$this->quick_edit->enqueue_scripts( 'edit.php' );

		$this->addToAssertionCount( 1 );
		$post_type = null;
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::enqueue_scripts
	 */
	public function test_enqueue_scripts_falls_back_to_the_get_post_type_param_when_the_global_is_unset() {
		global $post_type;
		$post_type = null;
		$_GET['post_type'] = 'book';

		\WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		$this->stubSchedulablePostTypes( array( 'book' ) );

		\WP_Mock::userFunction( 'wp_enqueue_script' )->once();

		$this->quick_edit->enqueue_scripts( 'edit.php' );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Neither source is available -- e.g. a plugin's own custom list screen
	 * that never sets the global and omits the query param. `current_post_type()`
	 * falls back to `'post'` rather than enqueueing for an unknown type.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::enqueue_scripts
	 */
	public function test_enqueue_scripts_falls_back_to_post_when_neither_source_is_set() {
		global $post_type;
		$post_type = null;
		unset( $_GET['post_type'] );

		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'wp_enqueue_script' )->once();

		$this->quick_edit->enqueue_scripts( 'edit.php' );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// render()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::render
	 */
	public function test_render_emits_nothing_for_an_unrelated_column() {
		\WP_Mock::userFunction( 'wp_nonce_field' )->never();

		ob_start();
		$this->quick_edit->render( 'date', 'post' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::render
	 */
	public function test_render_emits_nothing_for_a_non_schedulable_post_type() {
		$this->stubSchedulablePostTypes( array( 'post' ) );
		\WP_Mock::userFunction( 'wp_nonce_field' )->never();

		ob_start();
		$this->quick_edit->render( 'aps_scheduled', 'attachment' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The rendered markup carries the nonce field, a real datetime-local
	 * input, and a Clear checkbox -- the row's own data is populated
	 * client-side, so nothing here reads a specific post's schedule.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::render
	 */
	public function test_render_outputs_nonce_date_field_and_clear_checkbox() {
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'wp_nonce_field' )
			->once()->with( 'aps_schedule_quick_edit', 'aps_schedule_quick_edit_nonce', false )->andReturn( '' );

		ob_start();
		$this->quick_edit->render( 'aps_scheduled', 'post' );
		$output = ob_get_clean();

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $output, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$date_inputs = $doc->getElementsByTagName( 'input' );
		$found_date  = false;
		$found_clear = false;
		foreach ( $date_inputs as $input ) {
			if ( 'aps_schedule_quick_date' === $input->getAttribute( 'name' ) ) {
				$found_date = true;
				$this->assertSame( 'datetime-local', $input->getAttribute( 'type' ) );
			}
			if ( 'aps_schedule_quick_clear' === $input->getAttribute( 'name' ) ) {
				$found_clear = true;
				$this->assertSame( 'checkbox', $input->getAttribute( 'type' ) );
			}
		}

		$this->assertTrue( $found_date, 'The datetime-local date field must be present.' );
		$this->assertTrue( $found_clear, 'The Clear checkbox must be present.' );
	}

	// -----------------------------------------------------------------------
	// save() -- guards
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_does_nothing_when_the_nonce_field_is_missing() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_does_nothing_when_the_nonce_is_invalid() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );

		$_POST['aps_schedule_quick_edit_nonce'] = 'bad-nonce';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_verify_nonce' )->with( 'bad-nonce', 'aps_schedule_quick_edit' )->andReturn( false );

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_does_nothing_during_an_autosave() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( 42 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_does_nothing_for_a_revision() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( 42 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A well-formed date and a checked Clear box are both populated in
	 * $_POST so a bypassed capability gate would actually reach a write --
	 * an empty $_POST would let the write paths no-op on their own and mask
	 * the gate entirely.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_does_nothing_when_user_cannot_archive() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_quick_date'] = '2024-06-15T10:30';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
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

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() -- Clear
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_clear_checkbox_unschedules_through_the_public_api() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_quick_clear'] = '1';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_unschedule_archive' )->once()->with( 42 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Clear takes precedence when both the checkbox and a leftover date
	 * value are present in the same request -- a stale pre-filled date next
	 * to a deliberately checked Clear box must never win.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_clear_checkbox_wins_over_a_populated_date_field() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_quick_clear'] = '1';
		$_POST['aps_schedule_quick_date']  = '2024-06-15T10:30';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_unschedule_archive' )->once()->with( 42 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() -- exact date
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_sets_a_manual_schedule_from_a_well_formed_date() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_quick_date'] = '2024-06-15T10:30';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'aps_schedule_archive' )
			->once()->with( 42, 1718447400, 'manual' )->andReturn( true );

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * An empty date submission with no Clear checkbox is a no-op.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_does_nothing_when_the_date_field_is_empty() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Unparseable date input is left untouched rather than guessed at.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleQuickEdit::save
	 */
	public function test_save_ignores_unparseable_date_input() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidNonce();
		$_POST['aps_schedule_quick_date'] = 'not-a-date';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->quick_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}
}
