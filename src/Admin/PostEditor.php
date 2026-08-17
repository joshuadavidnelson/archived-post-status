<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Archive\ArchiveAction;
use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\ScheduleMeta;
use ArchivedPostStatus\Schedule\ScheduleSource;
use ArchivedPostStatus\Schedule\ScheduleTime;
use ArchivedPostStatus\Settings\CascadeInheritance;
use ArchivedPostStatus\Settings\PostInheritance;
use ArchivedPostStatus\Settings\Schema;

/**
 * Enqueues post editor assets and renders the classic editor archive button.
 *
 * Owns only asset loading and the submit-box button — nothing else. Access
 * enforcement for archived posts (redirect after save, block edit access) is
 * handled entirely by PostEditorGuard, which owns load-post.php.
 *
 * Also enqueues `schedule-panel.js` (0.5.0 phase 11), the block editor's
 * scheduling `PluginDocumentSettingPanel` — the classic-editor counterpart
 * is {@see ScheduleMetaBox}, an independent `add_meta_boxes` hookable, not
 * anything this class renders itself.
 *
 * @since 0.4.0
 */
final class PostEditor implements HookableInterface {

	/**
	 * @return array<int, HookDescriptor>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) ),
			HookDescriptor::action( 'post_submitbox_start', array( $this, 'post_submitbox_archive_button' ) ),
		);
	}

	/**
	 * Add the archive button to the classic editor submit box.
	 *
	 * Gates on post type before capability, so the button never renders for an
	 * unsupported post type regardless of the capability result.
	 *
	 * @since 0.4.0
	 */
	public function post_submitbox_archive_button(): void {
		$post_id = get_the_ID();
		if ( ! aps_is_supported_post_type( get_post_type( $post_id ) ) ) {
			return;
		}

		$cap = ArchiveAction::Archive->capability_function();
		if ( ! $cap( $post_id ) ) {
			return;
		}

		printf(
			'<div id="archive-action" style="margin-right: 10px; float: left; line-height: calc(30/13);"><a class="submitdelete deletion" href="%s">%s</a></div>',
			esc_url( aps_get_archive_post_link( $post_id ) ),
			esc_html__( 'Archive', 'archived-post-status' )
		);
	}

	/**
	 * Enqueue block editor script on post editor screens.
	 *
	 * Skipped on the classic editor, where post_submitbox_start renders the
	 * button instead, and on unsupported post types.
	 *
	 * @since 0.4.0
	 * @param string $hook The current admin page hook.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- pure environment-introspection helper.
	 */
	public function enqueue_scripts( string $hook ): void {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		if ( EditorContext::is_classic_editor() ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! aps_is_supported_post_type( get_post_type( $post_id ) ) ) {
			return;
		}

		// Dependencies match what assets/js/block-editor.js actually calls.
		wp_enqueue_script(
			'aps-block-editor',
			ARCHIVED_POST_STATUS_URL . 'assets/js/block-editor.js',
			array( 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-i18n' ),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);

		wp_set_script_translations(
			'aps-block-editor',
			'archived-post-status',
			plugin_dir_path( dirname( __DIR__ ) ) . '/languages/'
		);

		$cap         = ArchiveAction::Archive->capability_function();
		$can_archive = $cap( $post_id );

		// ArchivePostLink::build() does not check capability, so gate here: a
		// user who cannot archive must not receive a working, nonce-signed
		// archiveUrl, even though the JS also checks canArchive before rendering.
		wp_localize_script(
			'aps-block-editor',
			'archivedPostStatus',
			array(
				'archiveUrl' => $can_archive ? aps_get_archive_post_link( $post_id ) : false,
				'canArchive' => $can_archive,
			)
		);

		$this->enqueue_schedule_panel( $post_id, $can_archive );
	}

	/**
	 * Enqueue and localize the block editor's scheduling panel — the
	 * `PluginDocumentSettingPanel` counterpart to {@see ScheduleMetaBox}.
	 *
	 * Every value localized here is a plain string/number/bool the panel
	 * renders or writes verbatim: no epoch, no `Date` construction, no
	 * offset math. The resolved-outcome text is pre-formatted server-side by
	 * {@see ScheduleOutcome::describe()} — the JS does no formatting of its
	 * own, per the plan's §5.9 "one control, four hosts" requirement that
	 * this box and the classic metabox show identical text.
	 *
	 * @since 0.5.0
	 * @param int  $post_id     The post being edited.
	 * @param bool $can_archive Whether the current user can archive this post.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object/settings-table/formatting accessors.
	 */
	private function enqueue_schedule_panel( int $post_id, bool $can_archive ): void {
		wp_enqueue_script(
			'aps-schedule-panel',
			ARCHIVED_POST_STATUS_URL . 'assets/js/schedule-panel.js',
			array( 'wp-element', 'wp-plugins', 'wp-edit-post', 'wp-data', 'wp-i18n' ),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);

		wp_set_script_translations(
			'aps-schedule-panel',
			'archived-post-status',
			plugin_dir_path( dirname( __DIR__ ) ) . '/languages/'
		);

		// Short-circuiting `&&` matters here, not just for readability: a
		// user who cannot archive this post never reaches
		// schedulable_post_types_includes() or the aps_schedule_panel_enabled
		// filter, and the resolved-outcome/cascade reads below never run for
		// them at all — the same "denied user gets no working data" shape
		// enqueue_scripts() already applies to archiveUrl above.
		$can_schedule = $can_archive
			&& self::schedulable_post_types_includes( get_post_type( $post_id ) )
			&& (bool) apply_filters( 'aps_schedule_panel_enabled', true, $post_id );

		wp_localize_script(
			'aps-schedule-panel',
			'archivedPostStatusSchedule',
			$can_schedule ? self::schedule_panel_data( $post_id ) : self::schedule_panel_disabled_data()
		);
	}

	/**
	 * The panel's localized data when the user can schedule this post.
	 *
	 * `daysStatusText` mirrors {@see \ArchivedPostStatus\Settings\CascadeField}'s
	 * own Locked/Off wording verbatim (plan §5.9: "one control, four hosts"),
	 * pre-formatted server-side like every other display string this panel
	 * receives — non-empty exactly when an ancestor freezes the cascade, in
	 * which case the JS renders it as read-only text INSTEAD OF the editable
	 * input, rather than silently accepting a value that would sit inert
	 * until a later unlock activates it.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return array<string, mixed>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical resolved-outcome/cascade-inheritance/value-object accessors.
	 */
	private static function schedule_panel_data( int $post_id ): array {
		return array(
			'canSchedule'    => true,
			'outcome'        => ScheduleOutcome::describe( $post_id ),
			'exactDate'      => self::manual_local_date( $post_id ),
			'days'           => self::own_days( $post_id ),
			'daysStatusText' => self::days_status_text( PostInheritance::resolve( $post_id ) ),
		);
	}

	/**
	 * The read-only text shown in place of the editable days input when an
	 * ancestor freezes the cascade — '' when it does not. Wording matches
	 * {@see \ArchivedPostStatus\Settings\CascadeField}'s own Locked/Off
	 * badge and notice text exactly.
	 *
	 * @since 0.5.0
	 * @param CascadeInheritance $inheritance
	 * @return string
	 */
	private static function days_status_text( CascadeInheritance $inheritance ): string {
		if ( $inheritance->off ) {
			return sprintf(
				/* translators: %s: the ancestor level that hid this setting, e.g. "Network". */
				__( 'Hidden by %s.', 'archived-post-status' ),
				(string) $inheritance->frozen_by_label
			);
		}

		if ( $inheritance->locked ) {
			return sprintf(
				/* translators: 1: the locked number of days. 2: the ancestor level that locked it, e.g. "Network". */
				__( '%1$d days — locked by %2$s', 'archived-post-status' ),
				(int) $inheritance->days,
				(string) $inheritance->frozen_by_label
			);
		}

		return '';
	}

	/**
	 * The panel's localized data when it must render nothing — mirrors
	 * {@see \ArchivedPostStatus\Admin\ScheduleOutcome} never being consulted
	 * for a post the current user cannot schedule.
	 *
	 * @since 0.5.0
	 * @return array<string, mixed>
	 */
	private static function schedule_panel_disabled_data(): array {
		return array(
			'canSchedule'    => false,
			'outcome'        => '',
			'exactDate'      => '',
			'days'           => '',
			'daysStatusText' => '',
		);
	}

	/**
	 * The current MANUAL schedule's local wall-clock string, or '' — the
	 * SAME "never pre-fill from a rule stamp" rule
	 * {@see ScheduleMetaBox::render_date_field()} applies, for the same
	 * reason: an untouched field must never be misread as an instruction to
	 * convert a rule stamp into a manual date.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical value-object/timezone-boundary accessors.
	 */
	private static function manual_local_date( int $post_id ): string {
		$meta = ScheduleMeta::for_post( $post_id );

		return ( $meta && ScheduleSource::Manual === $meta->source ) ? ScheduleTime::to_local( $meta->time ) : '';
	}

	/**
	 * The post's own stored cascade override, as a string for the panel's
	 * number input — '' when it has none.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return string
	 */
	private static function own_days( int $post_id ): string {
		$raw = get_post_meta( $post_id, PostRuleProvider::META_DAYS, true );

		return '' === $raw ? '' : (string) (int) $raw;
	}

	/**
	 * The post types the schedule panel renders for — the SAME
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

}
