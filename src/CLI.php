<?php
/**
 * Archived Date meta field.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

use \WP_CLI;

/**
 * All the functionality needed to support the "Archived Date" field.
 *
 * @since 0.4.0
 */
class CLI extends Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = 'cli';

	/**
	 * Register the hooks.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function register() {

		// Register the CLI commands.
		\add_action( 'cli_init', array( $this, 'cli' ) );

	}

	/**
	 * Register the CLI commands.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function cli() {

		if ( ! class_exists( __CLASS__ ) ) {
			return;
		}

		WP_CLI::add_command( 'post archive', array( $this, 'archive' ) );
		WP_CLI::add_command( 'post unarchive', array( $this, 'unarchive' ) );

	}

	/**
	 * Archive a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to archive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp post archive 123
	 *
	 * @since 0.4.0
	 * @param array $args       The arguments.
	 * @param array $assoc_args The associative arguments.
	 */
	public function archive( $args, $assoc_args ) {

		$status = 0;

		foreach ( $args as $obj_id ) {
			$result = $this->handle_action( $obj_id, $assoc_args, 'archive' );
			$status = $this->success_or_failure( $result );
		}

		exit( $status );

	}


	/**
	 * Unarchive a post.
	 *
	 * ## OPTIONS
	 *
	 * <post_id>
	 * : The ID of the post to unarchive.
	 *
	 * ## EXAMPLES
	 *
	 *     wp post unarchive 123
	 *
	 * @since 0.4.0
	 * @param array $args       The arguments.
	 * @param array $assoc_args The associative arguments.
	 */
	public function unarchive( $args, $assoc_args ) {

		$status = 0;

		foreach ( $args as $obj_id ) {
			$result = $this->handle_action( $obj_id, $assoc_args, 'unarchive' );
			$status = $this->success_or_failure( $result );
		}

		exit( $status );

	}

	/**
	 * Callback used to un/archive a post.
	 *
	 * @since 0.4.0
	 * @param $post_id    The ID of the post to un/archive.
	 * @param $assoc_args The associative arguments.
	 * @param $action     The action to perform, either 'archive' or 'unarchive'.
	 * @return array
	 */
	protected function handle_action( $post_id, $assoc_args, $action ) {

		// Check that the post type is supported
		$post_type = get_post_type( $post_id );
		if ( ! aps_is_supported_post_type( $post_type ) ) {
			return [ 'error', "Post {$post_id} is not a supported post type." ];
		}

		// Get the current status of the post.
		$status = get_post_status( $post_id );

		// Check that we're not trying to archive something that is already archived.
		if ( 'archive' === $action && 'archive' == $status ) {
			return [ 'error', "Post {$post_id} is already archived." ];
		}

		// Check that the current status can be archived, if that is the action.
		$archivable_states = [ 'publish', 'draft', 'pending', 'future' ];
		if ( 'archive' === $action && ! in_array( $status, $archivable_states, true ) ) {
			return [ 'error', "Post {$post_id} cannot be archived, '{$status}' is not an archivable state." ];
		}

		// Check that the current status can be unarchived, if that is the action.
		if ( 'unarchive' === $action && 'archive' !== $status ) {
			return [ 'error', "Post {$post_id} cannot be unarchived because it is not in the archive." ];
		}

		// Perform the action.
		$function = "aps_{$action}_post";
		if ( ! call_user_func( $function, $post_id ) ) {
			return [ 'error', "Failed to {$action} post {$post_id}." ];
		}

		// Return the success message.
		$actioned = $action . 'd';
		return [ 'success', "{$actioned} post {$post_id}." ];
	}

	/**
	 * Display success or warning based on response; return proper exit code.
	 *
	 * @param array $response Formatted from a CRUD callback.
	 * @return int $status
	 */
	protected function success_or_failure( $response ) {
		list( $type, $msg ) = $response;

		if ( 'success' === $type ) {
			WP_CLI::success( $msg );
			$status = 0;
		} else {
			WP_CLI::warning( $msg );
			$status = 1;
		}

		return $status;
	}
}
