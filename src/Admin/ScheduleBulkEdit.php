<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\ScheduleTime;
use ArchivedPostStatus\Settings\Schema;

/**
 * Bulk Edit's schedule control: a `bulk_edit_custom_box` row plus its own
 * `save_post` handler.
 *
 * A THREE-WAY control — No change (default) / Set / Clear — rather than a
 * single date field read as "blank means clear": WordPress fires the bulk
 * save once PER SELECTED POST for every field the Bulk Edit box carries, so
 * a blank-means-clear field would wipe every selected post's schedule the
 * moment an editor bulk-edited something unrelated (category, author, ...)
 * without ever touching this control. {@see ACTION_NO_CHANGE} is the
 * default-selected option and the ONLY value that reaches neither
 * {@see \aps_schedule_archive()} nor {@see \aps_unschedule_archive()} — see
 * {@see save()}.
 *
 * Registers on `save_post`, the same hook {@see ScheduleQuickEdit} and
 * {@see ScheduleMetaBox} use: WordPress's own bulk-edit AJAX handler
 * (`wp_ajax_inline_save()`'s bulk branch) loops over every selected post and
 * calls `wp_update_post()` once PER post, so `save_post` already fires once
 * per post. Gating the ownership-aware {@see \aps_current_user_can_archive()}
 * check inside THIS callback — rather than once before dispatching the whole
 * batch — means a batch containing one post the current user cannot archive
 * still processes every other selected post normally and skips only that one.
 *
 * `check_admin_referer( 'bulk-posts' )` verifies the SAME nonce action core's
 * own `wp_ajax_inline_save()` already checks before this ever runs — a
 * defense-in-depth re-check, not a new nonce this class invents.
 *
 * `datetime-local` is the ONLY date control here, matching
 * {@see ScheduleMetaBox} and {@see ScheduleQuickEdit} — no JS date
 * arithmetic anywhere: {@see ScheduleTime::to_timestamp()} is the only
 * place the submitted wall-clock STRING becomes a UTC epoch, server-side
 * (plan §5.3's one boundary). No JS is needed for this box at all: unlike
 * Quick Edit, Bulk Edit does not display any post's existing value.
 *
 * @since 0.5.0
 */
final class ScheduleBulkEdit implements HookableInterface {

	private const FIELD_ACTION     = 'aps_schedule_bulk_action';
	private const FIELD_DATE       = 'aps_schedule_bulk_date';
	private const ACTION_NO_CHANGE = '-1';
	private const ACTION_SET       = 'set';
	private const ACTION_CLEAR     = 'clear';

	/**
	 * @since 0.5.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'bulk_edit_custom_box', array( $this, 'render' ), 10, 2 ),
			HookDescriptor::action( 'save_post', array( $this, 'save' ), 10, 2 ),
		);
	}

	// -----------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * `bulk_edit_custom_box` callback.
	 *
	 * @since 0.5.0
	 * @param string $column_name The column this box is being rendered for.
	 * @param string $post_type   The current screen's post type.
	 * @return void
	 */
	public function render( string $column_name, string $post_type ): void {
		if ( ScheduleColumn::COLUMN_KEY !== $column_name || ! self::schedulable_post_types_includes( $post_type ) ) {
			return;
		}

		echo '<fieldset class="inline-edit-col-right inline-edit-aps-schedule"><div class="inline-edit-col">';

		echo '<label class="inline-edit-group wp-clearfix"><span class="title">'
			. esc_html__( 'Scheduled archive', 'archived-post-status' )
			. '</span><select name="' . esc_attr( self::FIELD_ACTION ) . '">';

		printf(
			'<option value="%1$s">%2$s</option>',
			esc_attr( self::ACTION_NO_CHANGE ),
			esc_html__( '— No change —', 'archived-post-status' )
		);
		printf(
			'<option value="%1$s">%2$s</option>',
			esc_attr( self::ACTION_SET ),
			esc_html__( 'Set scheduled archive date', 'archived-post-status' )
		);
		printf(
			'<option value="%1$s">%2$s</option>',
			esc_attr( self::ACTION_CLEAR ),
			esc_html__( 'Clear scheduled archive', 'archived-post-status' )
		);

		echo '</select></label>';

		printf(
			'<label class="inline-edit-group wp-clearfix"><span class="title">%1$s</span>'
			. '<input type="datetime-local" name="%2$s" class="aps-schedule-bulk-date" /></label>',
			esc_html__( 'Date', 'archived-post-status' ),
			esc_attr( self::FIELD_DATE )
		);

		echo '</div></fieldset>';
	}

	/**
	 * The post types Bulk Edit's schedule control renders for — the SAME
	 * `scheduled_archive_post_types` setting
	 * {@see ScheduleMetaBox::schedulable_post_types_includes()} reads.
	 *
	 * @since 0.5.0
	 * @param string $post_type The post type to check.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-default/supported-post-types accessors.
	 */
	private static function schedulable_post_types_includes( string $post_type ): bool {
		$configured = apply_filters(
			'aps_scheduled_archive_post_types',
			Schema::default_for( 'scheduled_archive_post_types' )
		);
		$configured = is_array( $configured ) ? $configured : array();
		$types      = $configured ? $configured : aps_get_supported_post_types();

		return in_array( $post_type, $types, true );
	}

	// -----------------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------------

	/**
	 * `save_post` callback.
	 *
	 * "No change" is checked FIRST, before the nonce, capability, or post
	 * type gates: it is a genuine no-op regardless of anything else about
	 * the request, and nothing below it can turn "no change" into a write.
	 *
	 * @since 0.5.0
	 * @param int           $post_id The post ID being saved.
	 * @param \WP_Post|null $post    The post object being saved.
	 * @return void
	 */
	public function save( int $post_id, ?\WP_Post $post ): void {
		if ( ! $post instanceof \WP_Post || ! isset( $_POST['bulk_edit'], $_POST[ self::FIELD_ACTION ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the nonce is verified immediately below via check_admin_referer(); this read only selects which branch to take.
		$action = sanitize_text_field( wp_unslash( $_POST[ self::FIELD_ACTION ] ) );

		if ( self::ACTION_NO_CHANGE === $action ) {
			return;
		}

		check_admin_referer( 'bulk-posts' );

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! aps_current_user_can_archive( $post_id ) || ! self::schedulable_post_types_includes( $post->post_type ) ) {
			return;
		}

		if ( self::ACTION_CLEAR === $action ) {
			aps_unschedule_archive( $post_id );
			return;
		}

		if ( self::ACTION_SET === $action ) {
			self::save_exact_date( $post_id );
		}
	}

	/**
	 * Save the exact-date field. Only ever SETS a manual schedule; an empty
	 * or unparseable submission is a no-op — the same rules as
	 * {@see ScheduleMetaBox::save_exact_date()}.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID being saved.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical timezone-boundary accessor.
	 */
	private static function save_exact_date( int $post_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); sanitize_text_field() below sanitizes the raw value.
		$raw = isset( $_POST[ self::FIELD_DATE ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_DATE ] ) ) : '';

		if ( '' === $raw ) {
			return;
		}

		$timestamp = ScheduleTime::to_timestamp( $raw );

		if ( null === $timestamp ) {
			return;
		}

		aps_schedule_archive( $post_id, $timestamp, 'manual' );
	}
}
