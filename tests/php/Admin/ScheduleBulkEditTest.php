<?php
/**
 * Admin\ScheduleBulkEdit Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit
 *
 * Covers the observable behaviors of ScheduleBulkEdit:
 *   - hooks() registers bulk_edit_custom_box and save_post
 *   - render() emits the three-way select (No change / Set / Clear) and the
 *     date field, gated on the Scheduled column and a schedulable post type
 *   - save() is a genuine no-op for "No change" -- no nonce check, no
 *     capability check, no write of any kind
 *   - save() verifies check_admin_referer( 'bulk-posts' ) for Set and Clear;
 *     rejects autosave/revision; gates capability and post type PER POST,
 *     so a batch containing one post the user cannot archive still
 *     processes every other selected post
 *   - Set and Clear each write through the public API; an empty or
 *     unparseable date submitted with Set is a no-op
 */

use ArchivedPostStatus\Admin\ScheduleBulkEdit;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit
 */
class ScheduleBulkEditTest extends TestCase {

	/**
	 * @var ScheduleBulkEdit
	 */
	protected $bulk_edit;

	public function set_up() {
		parent::set_up();
		$this->bulk_edit = new ScheduleBulkEdit();
	}

	public function tear_down() {
		unset(
			$_POST['bulk_edit'],
			$_POST['aps_schedule_bulk_action'],
			$_POST['aps_schedule_bulk_date']
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

	/**
	 * A bulk-edit request always carries `bulk_edit`; the nonce check_admin_referer
	 * mock returns truthy (a valid nonce), standing in for core's own
	 * `check_admin_referer()`.
	 */
	private function stubValidBulkRequest( string $action ): void {
		$_POST['bulk_edit']              = 'Update';
		$_POST['aps_schedule_bulk_action'] = $action;
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'check_admin_referer' )->once()->with( 'bulk-posts' )->andReturn( 1 );
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::hooks
	 */
	public function test_hooks_registers_bulk_edit_box_and_save_post() {
		$hooks = $this->bulk_edit->hooks();

		$this->assertCount( 2, $hooks );

		$this->assertSame( 'action', $hooks[0]->type );
		$this->assertSame( 'bulk_edit_custom_box', $hooks[0]->hook );
		$this->assertSame( 2, $hooks[0]->accepted_args );

		$this->assertSame( 'action', $hooks[1]->type );
		$this->assertSame( 'save_post', $hooks[1]->hook );
		$this->assertSame( 2, $hooks[1]->accepted_args );
	}

	// -----------------------------------------------------------------------
	// render()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::render
	 */
	public function test_render_emits_nothing_for_an_unrelated_column() {
		ob_start();
		$this->bulk_edit->render( 'date', 'post' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::render
	 */
	public function test_render_emits_nothing_for_a_non_schedulable_post_type() {
		$this->stubSchedulablePostTypes( array( 'post' ) );

		ob_start();
		$this->bulk_edit->render( 'aps_scheduled', 'attachment' );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * The rendered markup carries a three-way `<select>` -- No change (the
	 * default, unselected-but-first option), Set, Clear -- plus the
	 * datetime-local date field.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::render
	 */
	public function test_render_outputs_the_three_way_select_and_date_field() {
		$this->stubSchedulablePostTypes();

		ob_start();
		$this->bulk_edit->render( 'aps_scheduled', 'post' );
		$output = ob_get_clean();

		$doc  = new \DOMDocument();
		$prev = libxml_use_internal_errors( true );
		$doc->loadHTML( '<!doctype html><meta charset="utf-8">' . $output, LIBXML_NOERROR | LIBXML_NOWARNING );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$selects = $doc->getElementsByTagName( 'select' );
		$this->assertSame( 1, $selects->length );
		$this->assertSame( 'aps_schedule_bulk_action', $selects->item( 0 )->getAttribute( 'name' ) );

		$options = $selects->item( 0 )->getElementsByTagName( 'option' );
		$this->assertSame( 3, $options->length );
		$this->assertSame( '-1', $options->item( 0 )->getAttribute( 'value' ) );
		$this->assertSame( 'set', $options->item( 1 )->getAttribute( 'value' ) );
		$this->assertSame( 'clear', $options->item( 2 )->getAttribute( 'value' ) );

		$date_found = false;
		foreach ( $doc->getElementsByTagName( 'input' ) as $input ) {
			if ( 'aps_schedule_bulk_date' === $input->getAttribute( 'name' ) ) {
				$date_found = true;
				$this->assertSame( 'datetime-local', $input->getAttribute( 'type' ) );
			}
		}
		$this->assertTrue( $date_found, 'The datetime-local date field must be present.' );
	}

	// -----------------------------------------------------------------------
	// save() -- non-bulk-edit requests
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_does_nothing_when_bulk_edit_flag_is_absent() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$_POST['aps_schedule_bulk_action'] = 'set';

		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_does_nothing_when_the_action_field_is_absent() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$_POST['bulk_edit'] = 'Update';

		\WP_Mock::userFunction( 'check_admin_referer' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() -- No change: the dangerous default, must be an unambiguous no-op
	// -----------------------------------------------------------------------

	/**
	 * The non-negotiable property: "No change" writes NOTHING. Not just
	 * "the two convenience functions weren't called" -- no nonce check, no
	 * capability check, no meta write of any kind. WordPress fires this
	 * save once per selected post regardless of which OTHER field an editor
	 * actually meant to bulk-edit, so this is the single most dangerous
	 * default in the whole control.
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_no_change_writes_nothing_at_all() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$_POST['bulk_edit']                = 'Update';
		$_POST['aps_schedule_bulk_action'] = '-1';

		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );

		\WP_Mock::userFunction( 'check_admin_referer' )->never();
		\WP_Mock::userFunction( 'wp_is_post_autosave' )->never();
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();
		\WP_Mock::userFunction( 'update_post_meta' )->never();
		\WP_Mock::userFunction( 'delete_post_meta' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() -- nonce, autosave, revision, capability, post type
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_rejects_a_bad_nonce() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$_POST['bulk_edit']                = 'Update';
		$_POST['aps_schedule_bulk_action'] = 'set';

		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'check_admin_referer' )
			->once()->with( 'bulk-posts' )
			->andReturnUsing(
				static function () {
					throw new \RuntimeException( 'bad nonce' );
				}
			);

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'bad nonce' );

		$this->bulk_edit->save( 42, $post );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_does_nothing_during_an_autosave() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$this->stubValidBulkRequest( 'set' );

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( 42 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_does_nothing_for_a_revision() {
		$post = $this->createMockPost( array( 'ID' => 42 ) );
		$this->stubValidBulkRequest( 'set' );

		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( 42 );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_does_nothing_when_user_cannot_archive() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidBulkRequest( 'set' );
		$_POST['aps_schedule_bulk_date'] = '2024-06-15T10:30';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_does_nothing_for_an_unsupported_post_type() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'attachment' ) );
		$this->stubValidBulkRequest( 'clear' );

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes( array( 'post' ) );

		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * The critical batch-gating property: a batch containing one post the
	 * user cannot archive still processes every OTHER selected post --
	 * proven here with two independent save() calls (mirroring how
	 * WordPress's own bulk-edit AJAX handler calls wp_update_post(), and
	 * therefore fires save_post, once per selected post).
	 *
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_gates_capability_per_post_not_per_batch() {
		$deniable_post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$allowed_post   = $this->createMockPost( array( 'ID' => 43, 'post_type' => 'post' ) );

		$_POST['bulk_edit']                = 'Update';
		$_POST['aps_schedule_bulk_action'] = 'clear';
		\WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( fn( $v ) => $v );
		\WP_Mock::userFunction( 'check_admin_referer' )->twice()->with( 'bulk-posts' )->andReturn( 1 );
		\WP_Mock::userFunction( 'wp_is_post_autosave' )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->andReturn( false );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 43 )->andReturn( true );

		\WP_Mock::userFunction( 'aps_unschedule_archive' )->once()->with( 43 )->andReturn( true );

		$this->bulk_edit->save( 42, $deniable_post );
		$this->bulk_edit->save( 43, $allowed_post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() -- Clear
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_clear_unschedules_through_the_public_api() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidBulkRequest( 'clear' );

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_unschedule_archive' )->once()->with( 42 )->andReturn( true );
		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	// -----------------------------------------------------------------------
	// save() -- Set
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_set_writes_a_manual_schedule_from_a_well_formed_date() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidBulkRequest( 'set' );
		$_POST['aps_schedule_bulk_date'] = '2024-06-15T10:30';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );
		\WP_Mock::userFunction( 'aps_schedule_archive' )
			->once()->with( 42, 1718447400, 'manual' )->andReturn( true );
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_set_does_nothing_when_the_date_field_is_empty() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidBulkRequest( 'set' );

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();
		\WP_Mock::userFunction( 'aps_unschedule_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @covers ArchivedPostStatus\Admin\ScheduleBulkEdit::save
	 */
	public function test_save_set_ignores_unparseable_date_input() {
		$post = $this->createMockPost( array( 'ID' => 42, 'post_type' => 'post' ) );
		$this->stubValidBulkRequest( 'set' );
		$_POST['aps_schedule_bulk_date'] = 'not-a-date';

		\WP_Mock::userFunction( 'wp_is_post_autosave' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'wp_is_post_revision' )->with( 42 )->andReturn( false );
		\WP_Mock::userFunction( 'aps_current_user_can_archive' )->with( 42 )->andReturn( true );
		$this->stubSchedulablePostTypes();
		\WP_Mock::userFunction( 'wp_timezone' )->andReturn( new \DateTimeZone( 'UTC' ) );

		\WP_Mock::userFunction( 'aps_schedule_archive' )->never();

		$this->bulk_edit->save( 42, $post );

		$this->addToAssertionCount( 1 );
	}
}
