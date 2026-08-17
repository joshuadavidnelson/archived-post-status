<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\ScheduleTime;
use ArchivedPostStatus\Settings\Schema;

/**
 * Quick Edit's schedule control: a `quick_edit_custom_box` row plus its own
 * `save_post` handler.
 *
 * This is scheduling — a future date — not the 0.3.x "Archived" status
 * option 0.4.0 deliberately removed from Quick Edit's status dropdown (see
 * changelog.md's 0.4.0 entry). Nothing here sets a post's status.
 *
 * WordPress does NOT pre-populate Quick Edit's custom fields: core's own
 * `inlineEditPost.edit()` only copies its OWN built-in columns out of the
 * row's DOM. {@see \assets\js\schedule-inline-edit.js} is the client half —
 * it reads {@see ScheduleColumnCellRenderer}'s hidden per-row data span and
 * writes it into this box's date field before the row becomes visible for
 * editing.
 *
 * `datetime-local` is the ONLY date control here, matching
 * {@see ScheduleMetaBox} — no JS date arithmetic anywhere: the browser
 * hands back an opaque wall-clock STRING, and {@see ScheduleTime::to_timestamp()}
 * is the only place it becomes a UTC epoch, server-side, exactly as the
 * classic metabox already does (plan §5.3's one boundary).
 *
 * Save routes exclusively through {@see \aps_schedule_archive()} /
 * {@see \aps_unschedule_archive()} — the same one-path design as every
 * other schedule-writing surface (plan §5.1).
 *
 * @since 0.5.0
 */
final class ScheduleQuickEdit implements HookableInterface {

	private const NONCE_ACTION = 'aps_schedule_quick_edit';
	private const NONCE_FIELD  = 'aps_schedule_quick_edit_nonce';
	private const FIELD_DATE   = 'aps_schedule_quick_date';
	private const FIELD_CLEAR  = 'aps_schedule_quick_clear';

	/**
	 * @since 0.5.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) ),
			HookDescriptor::action( 'quick_edit_custom_box', array( $this, 'render' ), 10, 2 ),
			HookDescriptor::action( 'save_post', array( $this, 'save' ), 10, 2 ),
		);
	}

	// -----------------------------------------------------------------------
	// Assets
	// -----------------------------------------------------------------------

	/**
	 * Enqueue the row-hydration script — list screens only, and only when
	 * the current screen's post type is schedulable.
	 *
	 * @since 0.5.0
	 * @param string $hook The current admin page hook.
	 * @return void
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( 'edit.php' !== $hook || ! self::schedulable_post_types_includes( self::current_post_type() ) ) {
			return;
		}

		wp_enqueue_script(
			'aps-schedule-inline-edit',
			ARCHIVED_POST_STATUS_URL . 'assets/js/schedule-inline-edit.js',
			array(),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);
	}

	/**
	 * The post type the current edit.php listing is showing.
	 *
	 * Mirrors {@see \ArchivedPostStatus\Admin\Notices::get_current_post_type()}'s
	 * global-then-request-param fallback shape, one level simpler: this only
	 * selects which post type's script to enqueue, nothing state-changing.
	 *
	 * @since 0.5.0
	 * @return string
	 */
	private static function current_post_type(): string {
		global $post_type;

		if ( $post_type ) {
			return (string) $post_type;
		}

		// Read-only: only selects which post type's script to enqueue.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post_type'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line via sanitize_key().
			return sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}

		return 'post';
	}

	// -----------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * `quick_edit_custom_box` callback.
	 *
	 * Fires once per registered list-table column while WordPress builds the
	 * single, shared inline-edit template row — there is no individual post
	 * in scope here, so rendering is gated on post type alone; per-post
	 * authorization happens at {@see save()}.
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

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD, false );

		echo '<fieldset class="inline-edit-col-right inline-edit-aps-schedule"><div class="inline-edit-col">';

		printf(
			'<label class="inline-edit-group wp-clearfix"><span class="title">%1$s</span>'
			. '<input type="datetime-local" name="%2$s" class="aps-schedule-quick-date" /></label>',
			esc_html__( 'Scheduled archive', 'archived-post-status' ),
			esc_attr( self::FIELD_DATE )
		);

		printf(
			'<label class="inline-edit-group wp-clearfix"><input type="checkbox" name="%1$s" value="1" />'
			. '<span class="checkbox-title">%2$s</span></label>',
			esc_attr( self::FIELD_CLEAR ),
			esc_html__( 'Clear scheduled archive', 'archived-post-status' )
		);

		echo '</div></fieldset>';
	}

	/**
	 * The post types Quick Edit's schedule field renders for — the SAME
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
	 * Guards, in order: nonce, autosave, revision, capability, schedulable
	 * post type — the same shape as {@see ScheduleMetaBox::save()}. Every
	 * write below routes through {@see \aps_schedule_archive()} /
	 * {@see \aps_unschedule_archive()}, never a raw meta write.
	 *
	 * Clear takes precedence over a submitted date when both are present in
	 * the same request: Quick Edit's Clear control is a checkbox alongside
	 * the date field (not a separate submit button like the metabox's
	 * dedicated Clear button), so a leftover pre-filled date value sitting
	 * next to a deliberately checked Clear box must never win.
	 *
	 * @since 0.5.0
	 * @param int           $post_id The post ID being saved.
	 * @param \WP_Post|null $post    The post object being saved.
	 * @return void
	 */
	public function save( int $post_id, ?\WP_Post $post ): void {
		if ( ! $post instanceof \WP_Post || ! self::nonce_valid() ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! aps_current_user_can_archive( $post_id ) || ! self::schedulable_post_types_includes( $post->post_type ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via nonce_valid().
		if ( isset( $_POST[ self::FIELD_CLEAR ] ) ) {
			aps_unschedule_archive( $post_id );
			return;
		}

		self::save_exact_date( $post_id );
	}

	/**
	 * @since 0.5.0
	 * @return bool
	 */
	private static function nonce_valid(): bool {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on the next line before use.
		$nonce = sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) );

		return false !== wp_verify_nonce( $nonce, self::NONCE_ACTION );
	}

	/**
	 * Save the exact-date field. Only ever SETS a manual schedule; an empty
	 * submission is a no-op and unparseable input is left untouched — the
	 * same rules as {@see ScheduleMetaBox::save_exact_date()}.
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
