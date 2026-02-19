<?php
/**
 * Post Editor functions.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Post editor functions.
 *
 * @since 0.4.0
 */
class PostEditor extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'post_editor';

	/**
	 * Register the feature.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register(): void {

		// Add the archive button to the block editor.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Add archive button to the classic editor.
		add_action( 'post_submitbox_start', array( $this, 'post_submitbox_archive_button' ) );

		// Prevent Archived content from being edited.
		add_action( 'load-post.php', array( $this, 'load_post_screen' ) );
	}

	/**
	 * Add the archive button to the post editor.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function post_submitbox_archive_button() {

		$post_id = get_the_ID();
		if ( aps_current_user_can_archive( $post_id ) ) {
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- aps_get_archive_post_link() returns escaped URL and __() returns safe translated string
			echo '<div id="archive-action" style="margin-right: 10px;float: left;
			line-height: calc( 30/ 13 );"><a class="submitdelete deletion" href="' . aps_get_archive_post_link( $post_id ) . '">' . __( 'Archive', 'archived-post-status' ) . '</a></div>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
		}

	}

	/**
	 * Enqueue the scripts for the block editor.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function enqueue_scripts( $hook ) {

		// Only enqueue the scripts on the post editor.
		if ( $this->is_classic_editor() ) {
			return;
		}

		// Only enqueue the scripts on the post editor.
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		// Enqueue the scripts.
		wp_enqueue_script(
			'aps-block-editor',
			ARCHIVED_POST_STATUS_URL . 'assets/js/block-editor.js',
			array(
				'wp-blocks',
				'wp-dom-ready',
				'wp-hooks',
				'wp-i18n'
			),
			ARCHIVED_POST_STATUS_VERSION,
			true
		);

		// Set the script translations.
		wp_set_script_translations(
			'aps-block-editor',
			'archived-post-status',
			plugin_dir_path( __FILE__ ) . '/languages/'
		);

		// Localize the script.
		wp_localize_script(
			'aps-block-editor',
			'archivedPostStatus',
			array(
				'archiveUrl' => aps_get_archive_post_link( get_the_ID() ),
				'canArchive' => aps_current_user_can_archive( get_the_ID() ),
			)
		);
	}

	/**
	 * Check if the current editor is the classic editor.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function is_classic_editor() {

		$current_screen = get_current_screen();

		// If we can determine this is a block editor, it's not classic
		if ( method_exists( $current_screen, 'is_block_editor' ) && $current_screen->is_block_editor() ) {
			return false;
		}

		// Otherwise, check if classic editor plugin is active
		return function_exists( 'is_plugin_active' ) && is_plugin_active( 'classic-editor/classic-editor.php' );
	}

	/**
	 * Prevent Archived content from being edited.
	 *
	 * @action load-post.php
	 */
	public function load_post_screen() {

		// Only disable editing if archive posts are read only.
		if ( ! aps_is_read_only() ) {
			return;
		}

		// Get post ID from URL parameters - get_query_var may not be available at this hook
		$post_id = 0;

		// Try multiple methods to get the post ID
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameter to identify post, not processing form data
		if ( isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
			$post_id = absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameter to identify post, not processing form data
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading form parameter to identify post, not processing/saving form data
		} elseif ( isset( $_POST['post_ID'] ) && is_numeric( $_POST['post_ID'] ) ) {
			$post_id = absint( $_POST['post_ID'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading form parameter to identify post, not processing/saving form data
		} elseif ( isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
			$post_id = $GLOBALS['post']->ID;
		}

		$post_type = get_post_type( $post_id );

		if ( ! absint( $post_id )
			|| ! $post_type
			|| ! aps_is_supported_post_type( $post_type )
			|| 'archive' !== get_post_status( $post_id ) ) {
				return;
		}

		// Get action and message from URL parameters
		$action  = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameter to determine admin action, not processing form data
		$message = isset( $_GET['message'] ) ? absint( $_GET['message'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading URL parameter to determine admin message, not processing form data

		// If this is an unarchive action, allow it.
		if ( 'unarchive' === $action ) {
			return;
		}

		// Redirect to list table after saving as Archived.
		if ( 'edit' === $action && 1 === $message ) {

			wp_safe_redirect(
				add_query_arg(
					'post_type',
					$post_type,
					self_admin_url( 'edit.php' )
				),
				302
			);

			exit;
		}

		// translators: Error message when trying to edit an Archived post.
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_die() handles escaping internally
		wp_die(
			__( "You can't edit this item because it has been Archived. Please change the post status and try again.", 'archived-post-status' ),
			__( 'WordPress &rsaquo; Error' ) // phpcs:ignore WordPress.WP.I18n.MissingArgDomain -- This is a WordPress core string and should not have a text domain.
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
