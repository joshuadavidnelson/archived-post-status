<?php

namespace ArchivedPostStatus\Admin;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Admin notices functionality for archive operations.
 *
 * Owns hook registration, screen detection, and HTML rendering; message
 * building is delegated to {@see NoticeBuilder}.
 *
 * @since 0.4.0
 */
final class Notices implements HookableInterface {

	/**
	 * Screen bases on which archive/unarchive notices are allowed to render.
	 *
	 * Every redirect that should produce a notice lands on edit.php — both
	 * {@see BulkActionHandler::get_redirect_url()} and
	 * {@see PostEditorGuard::redirect_to_list()} — so `edit` is the only base
	 * here. A new redirect surface must either land on one of these screens or
	 * add its base to this list.
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
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
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
		/**
		 * Filters whether the archive/unarchive admin notices should render.
		 *
		 * Return false to suppress the post-action notices entirely, success and
		 * skip-reason alike. Checked before screen detection or any query-var reads.
		 *
		 * @since 0.4.0
		 * @param bool $enabled Whether to render the notices. Default true.
		 * @return bool
		 */
		if ( ! apply_filters( 'aps_enable_notices', true ) ) {
			return;
		}

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

		// wp_admin_notice() landed in WordPress 6.4. The plugin supports 5.9,
		// so the guard is what keeps that support real — plugin-check reports
		// the call as incompatible because it cannot see the guard, and that
		// check is ignored in .github/workflows/plugin-check.yml for exactly
		// this reason.
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

		// Fallback for WordPress < 6.4.0 — the same markup wp_admin_notice()
		// produces for these arguments, so older installs see the notice too.
		printf(
			'<div id="message" class="notice notice-success is-dismissible"><p>%s</p></div>',
			wp_kses_post( $combined_message )
		);
	}

	/**
	 * Check if notices should be shown on current screen.
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

		// Read-only: this only picks which post type's list URL a notice links
		// back to. The action that produced the notice verified its own nonce
		// before redirecting here, and nothing below changes state.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['post_type'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return sanitize_key( wp_unslash( $_GET['post_type'] ) );
		}

		return get_post_type() ?: null;
	}
}
