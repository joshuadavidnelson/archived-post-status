<?php

namespace ArchivedPostStatus\Admin;

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Admin notices functionality for archive operations.
 *
 * Displays success notices for archive and unarchive operations
 * based on query parameters passed from bulk actions and row actions.
 *
 * Delegates message building to {@see NoticeBuilder}; this class owns only
 * the hook registration, screen detection, and HTML rendering responsibilities.
 *
 * @since 0.4.0
 */
final class Notices implements HookableInterface {

	/**
	 * Screen bases on which archive/unarchive notices are allowed to render.
	 *
	 * Redirect contract:
	 *   - {@see BulkActionHandler::get_redirect_url()} returns a URL pointing
	 *     at edit.php (with an optional `post_type` query arg). Both the
	 *     single-post `post_action_*` handlers in {@see PostList} and the
	 *     batch handler in `BulkActionHandler` route through this URL. The
	 *     resulting screen has base `edit`.
	 *   - {@see PostEditorGuard::redirect_to_list()} fires after a save on an
	 *     archived post, sending the user from `post.php` back to `edit.php`
	 *     (the list table). That screen also has base `edit`.
	 *
	 * Any new redirect surface that wants notices rendered must (a) land on
	 * a screen whose base is in this list, OR (b) add the new base here and
	 * supply a test pinning the entry.
	 *
	 * Stored as an array literal rather than a const so an external observer
	 * can override the list via a PHP global override in tests if needed.
	 * The list is small and stable; promotion to a class const is reasonable
	 * if more screens ever need to join.
	 *
	 * @since 0.4.0
	 * @var string[]
	 */
	private const ALLOWED_SCREEN_BASES = array( 'edit' );

	/**
	 * @since 0.4.0
	 */
	public function __construct(
		private readonly NoticeBuilder $builder,
	) {}

	/**
	 * Return an array of HookDescriptor objects.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor::action() is a
	 * named-constructor factory for the HookDescriptor value object; static
	 * access is the WP convention for value-object construction in hook registration.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'admin_notices', array( $this, 'display_notices' ) ),
		);
	}

	/**
	 * Display admin notices for archive/unarchive operations.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function display_notices(): void {
		if ( ! $this->should_show_notices() ) {
			return;
		}

		$current_post_type = $this->get_current_post_type();
		if ( ! $current_post_type ) {
			return;
		}

		$notices = $this->builder->build_notices( $current_post_type );

		if ( ! empty( $notices ) ) {
			$this->render_notices( $notices );
		}
	}

	/**
	 * Render the notices.
	 *
	 * @since 0.4.0
	 * @param array<int, string> $notices Array of notice messages.
	 * @return void
	 */
	private function render_notices( array $notices ): void {
		$combined_message = implode( ' ', $notices );

		if ( function_exists( 'wp_admin_notice' ) ) {
			wp_admin_notice(
				$combined_message,
				array(
					'id'                 => 'message',
					'additional_classes' => array( 'updated' ),
					'dismissible'        => true,
				)
			);
			return;
		}

		// Fallback for WordPress < 6.4.0
		printf(
			'<div id="message" class="notice notice-success is-dismissible"><p>%s</p></div>',
			wp_kses_post( $combined_message )
		);
	}

	/**
	 * Check if notices should be shown on current screen.
	 *
	 * Returns true only when the current admin screen's `base` is in
	 * {@see self::ALLOWED_SCREEN_BASES} — see the constant's docblock for
	 * the redirect contract that determines the allowed-base list.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	private function should_show_notices(): bool {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		return in_array( $screen->base, self::ALLOWED_SCREEN_BASES, true );
	}

	/**
	 * Get the current post type.
	 *
	 * @since 0.4.0
	 * @return string|null
	 */
	private function get_current_post_type(): ?string {
		global $post_type;

		if ( $post_type ) {
			return $post_type;
		}

		if ( isset( $_GET['post_type'] ) ) {
			return sanitize_key( $_GET['post_type'] );
		}

		return get_post_type() ?: null;
	}
}
