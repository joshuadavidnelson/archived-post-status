<?php

namespace ArchivedPostStatus\Status;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Registers the 'archive' post status and its admin display behavior.
 *
 * These two concerns are inseparable - both exist to present the archive status
 * in WordPress's admin UI.
 *
 * @since 0.4.0
 */
final class PostStatus implements HookableInterface {

	/**
	 * Return an array of HookDescriptor objects.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action()/::filter()
	 * are named-constructor factories for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'init', array( $this, 'register_status' ) ),
			HookDescriptor::filter( 'display_post_states', array( $this, 'display_post_states' ), 10, 2 ),
		);
	}

	/**
	 * Register a custom post status for Archived.
	 *
	 * @since 0.4.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor; the static call is the
	 * documented public surface, not a service-locator pull.
	 */
	public function register_status(): void {
		register_post_status( PostStatusValue::resolved_slug(), $this->status_args() );
	}

	/**
	 * Build the args array for register_post_status().
	 *
	 * Each arg is filterable so site owners can override defaults.
	 *
	 * The `label` arg is passed through unescaped by design (§1.6):
	 * register_post_status() 'label' is consumed by WP core, which is
	 * responsible for escaping it at whatever output site eventually
	 * renders it — the same way core treats its own built-in status
	 * labels. Escaping here would double-escape once core applies its own.
	 *
	 * @since 0.4.0
	 * @return array<string, mixed> Args ready for register_post_status().
	 */
	private function status_args(): array {
		/**
		 * Filter the `public` register_post_status() arg for the archived status.
		 *
		 * Per WP core, `public` controls whether posts of this status are shown
		 * on the front end of the site (it also influences the default of
		 * `publicly_queryable`). `public` and `private` are **independent**
		 * register_post_status() flags — they are not opposites; a status may
		 * legitimately be `public=false, private=false` (internal) or even
		 * `public=true, private=true` per WP core's contract.
		 *
		 * The default `! is_admin() && aps_current_user_can_view()` is a 0.4.0
		 * refinement: the status does not claim front-end-public registration on
		 * the admin side, where the `public` flag is not what gates admin
		 * visibility.
		 *
		 * @since 0.1.0 Defaulted to `false`.
		 * @since 0.3.0 Defaulted to `aps_current_user_can_view()`.
		 * @since 0.4.0 Changed default to `! is_admin() && aps_current_user_can_view()`
		 * @param bool $public Whether posts of this status are shown on the
		 *                     front end. Default `! is_admin() && aps_current_user_can_view()`.
		 * @return bool
		 */
		$public = (bool) apply_filters( 'aps_status_arg_public', ! is_admin() && aps_current_user_can_view() );

		/**
		 * Filter the `private` register_post_status() arg for the archived status.
		 *
		 * Pre 0.4.0 this was hardcoded to `true` which caused the status to be treated as private
		 * however we need it to be false in admin contexts to support not showing up in the main
		 * "All" admin list and to allow the "Archived" status filter to work.
		 *
		 * The `is_admin()` split in `public`/`private` toggles front-end
		 * visibility: the status is private on the front end (gated by the
		 * view capability), while in admin both flags stay false so the
		 * "All" list exclusion and the "Archived" filter work.
		 *
		 * @since 0.3.0 Defaulted to `true`.
		 * @since 0.4.0 Changed default to `! is_admin()`.
		 * @param bool $private Whether posts of this status are treated as
		 *                      private. Default `! is_admin()`.
		 * @return bool
		 */
		$private = (bool) apply_filters( 'aps_status_arg_private', ! is_admin() );

		/**
		 * Filter the protected status parameter.
		 *
		 * @since 0.4.0
		 * @param bool $protected True to make the status protected,
		 *                        defaults to false.
		 * @return bool
		 */
		$protected = (bool) apply_filters( 'aps_status_arg_protected', false );

		/**
		 * Filter the exclude from search status parameter.
		 *
		 * @since 0.4.0
		 * @param bool $exclude True to exclude archived content from search,
		 *                      false to include it.
		 *                      Defaults to true if the current
		 *                      user can't view archived content.
		 * @return bool
		 */
		$exclude_from_search = (bool) apply_filters( 'aps_status_arg_exclude_from_search', ! ( is_admin() && aps_current_user_can_view() ) );

		/**
		 * Filter the show in admin all list status parameter.
		 *
		 * Archived posts stay out of the admin "All" list by default (as
		 * core does with trash); the "Archived" filter browses them.
		 *
		 * @since 0.3.0 Defaulted to `aps_current_user_can_view()`.
		 * @since 0.4.0 Changed default to `false`.
		 * @param bool $show True to show archived content in the
		 *                   admin all list, false to hide it. Default false.
		 * @return bool
		 */
		$show_in_all_list = (bool) apply_filters( 'aps_status_arg_show_in_admin_all_list', false );

		/**
		 * Filter the show in admin status list status parameter.
		 *
		 * @since 0.4.0
		 * @param bool $show True to show archived content in the
		 *                   admin status list, false to hide it.
		 *                   Defaults to true if the current user can view archived content.
		 * @return bool
		 */
		$show_in_status_list = (bool) apply_filters( 'aps_status_arg_show_in_admin_status_list', aps_current_user_can_view() );

		/**
		 * Filter the icon used for the Archived post status.
		 *
		 * @since 0.4.0
		 * @param string $icon The dashicon name.
		 * @return string
		 */
		$icon = (string) apply_filters( 'aps_status_arg_dashicon', 'dashicons-archive' );

		return array(
			'label'                     => aps_archived_label_string(),
			'post_type'                 => aps_get_supported_post_types(),
			'public'                    => $public,
			'private'                   => $private,
			'protected'                 => $protected,
			'exclude_from_search'       => $exclude_from_search,
			'show_in_admin_all_list'    => $show_in_all_list,
			'show_in_admin_status_list' => $show_in_status_list,
			'dashicons'                 => $icon,
			/* translators: %s: post count */
			'label_count'               => _n_noop(
				'Archived <span class="count">(%s)</span>',
				'Archived <span class="count">(%s)</span>',
				'archived-post-status'
			),
		);
	}

	/**
	 * Display custom post state text next to post titles that are Archived.
	 *
	 * @since 0.4.0
	 * @param array<string, string> $post_states The current post states (state key => label).
	 * @param \WP_Post              $post        The post object.
	 * @return array<string, string>
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- {@see PostStatusValue::resolved_slug()}
	 * is the canonical filterable slug accessor consulted by every consumer
	 * that compares against `$post->post_status`.
	 */
	public function display_post_states( array $post_states, \WP_Post $post ): array {
		$slug = PostStatusValue::resolved_slug();

		if ( ! aps_is_supported_post_type( $post->post_type )
			|| $slug !== $post->post_status
			|| in_array( $slug, (array) get_query_var( 'post_status' ), true ) ) {
				return $post_states;
		}

		return array_merge(
			$post_states,
			array(
				// esc_html() here because this IS the output site: core's
				// _post_states() concatenates every post state directly
				// into raw HTML with no escaping of its own (§1.6).
				$slug => esc_html( aps_archived_label_string() ),
			)
		);
	}
}
