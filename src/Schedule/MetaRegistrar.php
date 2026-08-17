<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\Provider\PostRuleProvider;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Settings\PostInheritance;

/**
 * Registers {@see ScheduleMeta::META_TIME} and
 * {@see PostRuleProvider::META_DAYS} as REST-visible post meta, and keeps
 * {@see ScheduleMeta::META_SOURCE} in sync with META_TIME however it is
 * written.
 *
 * Only META_TIME and META_DAYS carry real domain meaning of their own; the
 * other three {@see ScheduleMeta} keys stay out of REST as internal
 * bookkeeping. `show_in_rest` is what lets the block editor panel (0.5.0
 * phase 11) save a schedule, and a post-level auto-archive override,
 * through the ordinary post save with no custom AJAX endpoint.
 *
 * Both keys share the same `auth_callback`: it checks the archive
 * capability for the specific post being saved, not a type-level
 * `edit_posts`. Scheduling a post, and setting its own auto-archive
 * override, are both pre-authorizing its eventual archive, so both take the
 * same capability {@see \ArchivedPostStatus\Archive\ArchiveCapability}
 * already grants for archiving that post outright. A type-level check would
 * let a Contributor pass it for a post they cannot touch.
 *
 * `added_post_meta` / `updated_post_meta` close the gap between the two
 * ways a schedule's "does this post have a schedule at all" question gets
 * answered: {@see ScheduleMeta::for_post()} treats presence of META_SOURCE
 * as record-existence, but {@see SweepQuery} matches on META_TIME. Writing
 * META_TIME directly -- the REST field this class registers is exactly
 * such a path -- would otherwise create a post the sweeper archives on
 * schedule while `for_post()` still reports no schedule at all. Backfilling
 * META_SOURCE to {@see ScheduleSource::Manual} whenever it is absent keeps
 * "META_TIME implies META_SOURCE" true regardless of which code wrote the
 * time.
 *
 * Two more keys exist purely as the block editor panel's (0.5.0 phase 11)
 * write-only input channels, never read back for display by anything in
 * this plugin:
 *
 *  - {@see META_LOCAL_INPUT} (string) carries the wall-clock string a
 *    `datetime-local` control produces, e.g. "2027-03-03T10:00". Registering
 *    META_TIME itself (an integer epoch) as the JS-bound field would force
 *    the browser's own JS to convert a local string to an epoch -- exactly
 *    the timezone arithmetic this release's invariant forbids in
 *    JavaScript (plan §5.3/Risk #4). This key is the bridge instead:
 *    {@see sync_from_local_input()} is the ONLY place that converts it, via
 *    {@see ScheduleTime::to_timestamp()}, before writing the real schedule
 *    through {@see ScheduleOperation::set()} -- never a raw META_TIME write.
 *    The epoch stays authoritative; the string is input-only, and an empty
 *    or unparseable submission is silently ignored rather than guessed at
 *    -- clearing is META_CLEAR_FLAG's job, not an empty string's.
 *  - {@see META_CLEAR_FLAG} (boolean) is the panel's Clear-button signal.
 *    {@see sync_from_clear_flag()} unschedules the post (and drops its own
 *    cascade override) the same way {@see \ArchivedPostStatus\Admin\ScheduleMetaBox}'s
 *    Clear button does -- a dedicated, unambiguous action, not inferred
 *    from any field being empty.
 *
 * Both new keys are covered by uninstall's existing `_aps_schedule_meta_%`
 * LIKE DELETE without any change there.
 *
 * {@see guard_frozen_days_override()} is the REST write path's counterpart
 * to {@see \ArchivedPostStatus\Admin\ScheduleMetaBox::save_days_override()}'s
 * own frozen-ancestor guard: a classic-editor POST body never reaches
 * `update_post_meta()` for META_DAYS while an ancestor is Locked or Off,
 * because the guard runs BEFORE the write there. The REST field registered
 * by this class has no such pre-write hook available to it -- WordPress
 * calls `update_post_meta()` directly from the REST controller -- so this
 * listener instead reverts the write immediately after it lands, closing
 * the same authorization-adjacent gap for the block editor panel: without
 * it, a value written while frozen would sit inert (the resolver stops
 * walking at the first frozen ancestor) until that ancestor is later
 * unlocked, at which point it activates with no further action from
 * whoever wrote it.
 *
 * @since 0.5.0
 */
final class MetaRegistrar implements HookableInterface {

	/**
	 * Write-only input channel: the wall-clock string a `datetime-local`
	 * control submits. See the class docblock.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const META_LOCAL_INPUT = '_aps_schedule_meta_local_input';

	/**
	 * Write-only input channel: the block editor panel's Clear-button
	 * signal. See the class docblock.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const META_CLEAR_FLAG = '_aps_schedule_meta_clear_flag';

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'init', array( $this, 'register_meta' ) ),
			HookDescriptor::action( 'added_post_meta', array( $this, 'backfill_source' ), 10, 4 ),
			HookDescriptor::action( 'updated_post_meta', array( $this, 'backfill_source' ), 10, 4 ),
			HookDescriptor::action( 'added_post_meta', array( $this, 'sync_from_local_input' ), 10, 4 ),
			HookDescriptor::action( 'updated_post_meta', array( $this, 'sync_from_local_input' ), 10, 4 ),
			HookDescriptor::action( 'added_post_meta', array( $this, 'sync_from_clear_flag' ), 10, 4 ),
			HookDescriptor::action( 'updated_post_meta', array( $this, 'sync_from_clear_flag' ), 10, 4 ),
			HookDescriptor::action( 'added_post_meta', array( $this, 'guard_frozen_days_override' ), 10, 4 ),
			HookDescriptor::action( 'updated_post_meta', array( $this, 'guard_frozen_days_override' ), 10, 4 ),
		);
	}

	/**
	 * `init` callback: registers META_TIME, the post-level auto-archive
	 * override, and the block editor panel's two write-only input channels,
	 * for every supported post type.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( aps_get_supported_post_types() as $post_type ) {
			register_post_meta( $post_type, ScheduleMeta::META_TIME, self::meta_args( 'integer' ) );
			register_post_meta( $post_type, PostRuleProvider::META_DAYS, self::meta_args( 'integer' ) );
			register_post_meta( $post_type, self::META_LOCAL_INPUT, self::meta_args( 'string', '' ) );
			register_post_meta( $post_type, self::META_CLEAR_FLAG, self::meta_args( 'boolean', false ) );
		}
	}

	/**
	 * The `register_post_meta()` args shared by every key this class
	 * registers, for every supported post type -- all gated on the same
	 * per-post auth_callback. Its own method so a test can inspect the exact
	 * array a live `register_post_meta()` call receives.
	 *
	 * @since 0.5.0
	 * @param string $type    The REST schema type: 'integer', 'string', or 'boolean'.
	 * @param mixed  $default Optional. The default value when nothing is stored.
	 * @return array<string, mixed>
	 */
	private static function meta_args( string $type, mixed $default = null ): array {
		$args = array(
			'type'          => $type,
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => array( self::class, 'auth_callback' ),
		);

		if ( null !== $default ) {
			$args['default'] = $default;
		}

		return $args;
	}

	/**
	 * The shared `auth_callback` for both keys' REST registration.
	 *
	 * @since 0.5.0
	 * @param bool   $allowed Whether the meta key is currently allowed
	 *                        (unused; part of the locked auth_callback signature).
	 * @param string $meta_key The meta key being authorized (unused; part
	 *                         of the locked auth_callback signature).
	 * @param int    $post_id  The post this schedule or override would apply to.
	 * @return bool
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked auth_callback signature.
	 */
	public static function auth_callback( $allowed, $meta_key, $post_id ): bool {
		return aps_current_user_can_archive( (int) $post_id );
	}

	/**
	 * `added_post_meta` / `updated_post_meta` callback: backfills
	 * META_SOURCE to `manual` whenever META_TIME is written with no source
	 * already on record.
	 *
	 * @since 0.5.0
	 * @param int    $meta_id    The meta row ID (unused; part of the locked 4-arg signature).
	 * @param int    $post_id    The post ID the meta belongs to.
	 * @param string $meta_key   The meta key written.
	 * @param mixed  $meta_value The value written (unused; part of the locked 4-arg signature).
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 4-arg hook signature.
	 */
	public function backfill_source( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( ScheduleMeta::META_TIME !== $meta_key ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( self::has_source( $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, ScheduleMeta::META_SOURCE, ScheduleSource::Manual->value );
	}

	/**
	 * Whether a post already has a schedule source on record.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID to check.
	 * @return bool
	 */
	private static function has_source( int $post_id ): bool {
		return '' !== (string) get_post_meta( $post_id, ScheduleMeta::META_SOURCE, true );
	}

	/**
	 * `added_post_meta` / `updated_post_meta` callback: converts a written
	 * META_LOCAL_INPUT string into the authoritative epoch, via
	 * {@see ScheduleTime::to_timestamp()} and {@see ScheduleOperation::set()}
	 * -- the ONLY place this class writes a real schedule from this channel.
	 * An empty or unparseable value is silently ignored -- see the class
	 * docblock for why an empty string never clears anything here.
	 *
	 * @since 0.5.0
	 * @param int    $meta_id    The meta row ID (unused; part of the locked 4-arg signature).
	 * @param int    $post_id    The post ID the meta belongs to.
	 * @param string $meta_key   The meta key written.
	 * @param mixed  $meta_value The wall-clock string written.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 4-arg hook signature.
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical timezone-boundary/schedule-operation accessors.
	 */
	public function sync_from_local_input( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( self::META_LOCAL_INPUT !== $meta_key ) {
			return;
		}

		$local = trim( (string) $meta_value );

		if ( '' === $local ) {
			return;
		}

		$timestamp = ScheduleTime::to_timestamp( $local );

		if ( null === $timestamp ) {
			return;
		}

		ScheduleOperation::set( (int) $post_id, $timestamp, ScheduleSource::Manual );
	}

	/**
	 * `added_post_meta` / `updated_post_meta` callback: a truthy
	 * META_CLEAR_FLAG write clears the post's schedule and drops its own
	 * cascade override, mirroring {@see \ArchivedPostStatus\Admin\ScheduleMetaBox}'s
	 * Clear button exactly.
	 *
	 * @since 0.5.0
	 * @param int    $meta_id    The meta row ID (unused; part of the locked 4-arg signature).
	 * @param int    $post_id    The post ID the meta belongs to.
	 * @param string $meta_key   The meta key written.
	 * @param mixed  $meta_value The clear-flag value written.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 4-arg hook signature.
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical schedule-operation accessor.
	 */
	public function sync_from_clear_flag( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( self::META_CLEAR_FLAG !== $meta_key || ! $meta_value ) {
			return;
		}

		$post_id = (int) $post_id;

		ScheduleOperation::clear( $post_id );
		delete_post_meta( $post_id, PostRuleProvider::META_DAYS );
	}

	/**
	 * `added_post_meta` / `updated_post_meta` callback: reverts a META_DAYS
	 * write made while an ancestor freezes the cascade for this post -- the
	 * REST write path's counterpart to {@see \ArchivedPostStatus\Admin\ScheduleMetaBox::save_days_override()}'s
	 * own pre-write guard. See the class docblock for why this is a
	 * revert-after rather than a block-before.
	 *
	 * @since 0.5.0
	 * @param int    $meta_id    The meta row ID (unused; part of the locked 4-arg signature).
	 * @param int    $post_id    The post ID the meta belongs to.
	 * @param string $meta_key   The meta key written.
	 * @param mixed  $meta_value The value written (unused; part of the locked 4-arg signature).
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked 4-arg hook signature.
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical cascade-inheritance accessor.
	 */
	public function guard_frozen_days_override( $meta_id, $post_id, $meta_key, $meta_value ): void {
		if ( PostRuleProvider::META_DAYS !== $meta_key ) {
			return;
		}

		$post_id = (int) $post_id;

		if ( PostInheritance::resolve( $post_id )->frozen() ) {
			delete_post_meta( $post_id, PostRuleProvider::META_DAYS );
		}
	}
}
