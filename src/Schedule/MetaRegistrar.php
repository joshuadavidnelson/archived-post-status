<?php

namespace ArchivedPostStatus\Schedule;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Registers {@see ScheduleMeta::META_TIME} as REST-visible post meta, and
 * keeps {@see ScheduleMeta::META_SOURCE} in sync with it however it is
 * written.
 *
 * Only META_TIME is registered with `register_post_meta()` -- the other
 * four {@see ScheduleMeta} keys are internal bookkeeping and deliberately
 * stay out of REST. `show_in_rest` is what lets the block editor panel
 * (phase 11) save a schedule through the ordinary post save with no custom
 * AJAX endpoint.
 *
 * The `auth_callback` checks the archive capability for the specific post
 * being saved, not a type-level `edit_posts`: scheduling a post is
 * pre-authorizing its eventual archive, so it takes the same capability
 * {@see \ArchivedPostStatus\Archive\ArchiveCapability} already grants for
 * archiving that post outright. A type-level check would let a Contributor
 * pass it for a post they cannot touch.
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
 * @since 0.5.0
 */
final class MetaRegistrar implements HookableInterface {

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
		);
	}

	/**
	 * `init` callback: registers META_TIME for every supported post type.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register_meta(): void {
		foreach ( aps_get_supported_post_types() as $post_type ) {
			register_post_meta( $post_type, ScheduleMeta::META_TIME, self::meta_args() );
		}
	}

	/**
	 * The `register_post_meta()` args shared by every supported post type.
	 * Its own method so a test can inspect the exact array a live
	 * `register_post_meta()` call receives.
	 *
	 * @since 0.5.0
	 * @return array<string, mixed>
	 */
	private static function meta_args(): array {
		return array(
			'type'          => 'integer',
			'single'        => true,
			'show_in_rest'  => true,
			'auth_callback' => array( self::class, 'auth_callback' ),
		);
	}

	/**
	 * The `auth_callback` for META_TIME's REST registration.
	 *
	 * @since 0.5.0
	 * @param bool   $allowed Whether the meta key is currently allowed
	 *                        (unused; part of the locked auth_callback signature).
	 * @param string $meta_key The meta key being authorized (unused; part
	 *                         of the locked auth_callback signature).
	 * @param int    $post_id  The post this schedule would apply to.
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
}
