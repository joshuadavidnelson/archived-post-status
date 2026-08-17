<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Schedule\ScheduleTime;
use ArchivedPostStatus\Settings\CascadeField;
use ArchivedPostStatus\Settings\PostInheritance;
use ArchivedPostStatus\Settings\Schema;

/**
 * The classic-editor metabox for per-post scheduling (plan §5.9): the
 * resolved-outcome line, an exact-date control, a per-post "after N days"
 * cascade override, and a Clear control.
 *
 * `datetime-local` is the ONLY date control this box renders — no hour /
 * minute / meridiem trio, no inline clamping JS. {@see ScheduleTime} is the
 * one place the submitted wall-clock string becomes a UTC epoch; this class
 * never touches a timezone itself.
 *
 * The days-override control is the SAME {@see CascadeField} renderer the
 * network, site, and term screens use (plan §5.9: "one control, four
 * hosts"), driven by {@see PostInheritance} — so an ancestor's Locked/Off
 * state shows and behaves identically here, badge included, with no
 * bespoke inheritance UI of its own.
 *
 * Clear is a dedicated `<button>` rather than "submit an emptied field":
 * the date field is pre-filled ONLY when a MANUAL schedule already exists,
 * so an untouched (naturally blank, for the common case of no manual date)
 * field must never be silently read as "cancel the schedule" on an
 * unrelated save. Clearing this post's own instructions is a deliberate,
 * one-click action instead.
 *
 * @since 0.5.0
 */
final class ScheduleMetaBox implements HookableInterface {

	private const META_BOX_ID  = 'aps-schedule';
	private const NONCE_ACTION = 'aps_schedule_meta_box';
	private const NONCE_FIELD  = 'aps_schedule_meta_box_nonce';
	private const FIELD_DATE   = 'aps_schedule_date';
	private const FIELD_DAYS   = 'aps_schedule_days_override';
	private const FIELD_CLEAR  = 'aps_schedule_clear';
	private const OUTCOME_ID   = 'aps-schedule-outcome';

	/**
	 * @since 0.5.0
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'add_meta_boxes', array( $this, 'add_meta_box_maybe' ), 10, 2 ),
			HookDescriptor::action( 'save_post', array( $this, 'save' ), 10, 2 ),
		);
	}

	// -----------------------------------------------------------------------
	// Registration
	// -----------------------------------------------------------------------

	/**
	 * `add_meta_boxes` callback: registers the box for one post, when its
	 * type is schedulable, the current user can archive it, and no filter
	 * has hidden the control for it.
	 *
	 * @since 0.5.0
	 * @param string        $post_type The post type of the screen being rendered.
	 * @param \WP_Post|null $post      The post being edited.
	 * @return void
	 */
	public function add_meta_box_maybe( string $post_type, ?\WP_Post $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		if ( ! self::schedulable_post_types_includes( $post_type ) ) {
			return;
		}

		if ( ! aps_current_user_can_archive( $post->ID ) || ! self::panel_enabled( $post->ID ) ) {
			return;
		}

		add_meta_box(
			self::META_BOX_ID,
			__( 'Archive Schedule', 'archived-post-status' ),
			array( $this, 'render' ),
			$post_type,
			'side',
			'default'
		);
	}

	/**
	 * Whether the editor control renders for a given post — plan §5.11's
	 * `aps_schedule_panel_enabled` filter, shared with the block editor
	 * panel (via {@see PostEditor}'s capability flag).
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return bool
	 */
	private static function panel_enabled( int $post_id ): bool {

		/**
		 * Filters whether the schedule editor control (classic metabox and
		 * block editor panel alike) renders for a given post.
		 *
		 * @since 0.5.0
		 * @param bool $enabled Whether to render the control. Default true.
		 * @param int  $post_id The post ID.
		 */
		return (bool) apply_filters( 'aps_schedule_panel_enabled', true, $post_id );
	}

	/**
	 * The post types this box renders for — the SAME `scheduled_archive_post_types`
	 * setting {@see ScheduleColumn::schedulable_post_types()} reads, with the
	 * same "empty means every supported type" convention.
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
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * Render the box: the resolved-outcome line, the exact-date control, the
	 * days-override cascade field, and the Clear button.
	 *
	 * @since 0.5.0
	 * @param \WP_Post $post The post being edited.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object/settings-table accessors.
	 */
	public function render( \WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		printf(
			'<p id="%1$s">%2$s</p>',
			esc_attr( self::OUTCOME_ID ),
			esc_html( ScheduleOutcome::describe( $post->ID ) )
		);

		$this->render_date_field( $post->ID );

		$cascade_field = CascadeField::render(
			self::FIELD_DAYS,
			Schema::label_for( 'auto_archive_days' ),
			Schema::description_for( 'auto_archive_days' ),
			PostInheritance::resolve( $post->ID ),
			self::own_days( $post->ID )
		);
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CascadeField::render() returns already-escaped HTML, see its own no-double-escape contract.
		echo $cascade_field;

		printf(
			'<p><button type="submit" class="button" name="%1$s" value="1">%2$s</button></p>',
			esc_attr( self::FIELD_CLEAR ),
			esc_html__( 'Clear schedule', 'archived-post-status' )
		);
	}

	/**
	 * The exact-date `datetime-local` field. Pre-filled ONLY from an
	 * existing MANUAL schedule — never from a rule-stamped one, which the
	 * resolved-outcome line above already explains. Pre-filling from a rule
	 * stamp would make an untouched, resubmitted field indistinguishable
	 * from a deliberate instruction to convert that rule stamp to a manual
	 * date on the next unrelated save.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object/display-formatting accessors.
	 */
	private function render_date_field( int $post_id ): void {
		$meta  = ScheduleMeta::for_post( $post_id );
		$local = ( $meta && ScheduleSource::Manual === $meta->source )
			? ScheduleTime::to_local( $meta->time )
			: '';

		printf(
			'<p><label for="%1$s">%2$s</label><br /><input type="datetime-local" id="%1$s" name="%1$s" value="%3$s" aria-describedby="%4$s" /></p>',
			esc_attr( self::FIELD_DATE ),
			esc_html__( 'Exact date', 'archived-post-status' ),
			esc_attr( $local ),
			esc_attr( self::OUTCOME_ID )
		);
	}

	/**
	 * The post's own stored cascade override, or null when it has none.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return ?int
	 */
	private static function own_days( int $post_id ): ?int {
		$raw = get_post_meta( $post_id, PostRuleProvider::META_DAYS, true );

		return '' === $raw ? null : (int) $raw;
	}

	// -----------------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------------

	/**
	 * `save_post` callback.
	 *
	 * Guards, in order: nonce, autosave, revision, capability, schedulable
	 * post type. Every write below routes through {@see \aps_schedule_archive()}
	 * / {@see \aps_unschedule_archive()} — never a raw meta write — so the
	 * schedule hooks fire, per the plan's §5.1 one-path design.
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
			delete_post_meta( $post_id, PostRuleProvider::META_DAYS );
			return;
		}

		self::save_days_override( $post_id );
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
	 * Save the days-override field. An ancestor freezing the cascade
	 * (plan §4.1) means the control was never rendered for the user to
	 * submit, so the stored override is left exactly as it is — mirroring
	 * {@see \ArchivedPostStatus\Settings\TermFields::save()}'s identical
	 * guard, and blocking a forged POST body the same way.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID being saved.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/cascade-inheritance accessors.
	 */
	private static function save_days_override( int $post_id ): void {
		if ( PostInheritance::resolve( $post_id )->frozen() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); the raw value is sanitized on the next line via Schema's own sanitizer.
		$raw = isset( $_POST[ self::FIELD_DAYS ] ) ? wp_unslash( $_POST[ self::FIELD_DAYS ] ) : '';
		$raw = '' === trim( (string) $raw ) ? null : $raw;

		$days = ( Schema::sanitizer_for( 'auto_archive_days' ) )( $raw );

		if ( null === $days ) {
			delete_post_meta( $post_id, PostRuleProvider::META_DAYS );
			return;
		}

		update_post_meta( $post_id, PostRuleProvider::META_DAYS, $days );
	}

	/**
	 * Save the exact-date field. Only ever SETS a manual schedule — an empty
	 * submission is a no-op, per {@see render_date_field()}'s own rationale;
	 * clearing an existing schedule is the dedicated Clear button's job.
	 * Unparseable input is left untouched rather than guessed at.
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
