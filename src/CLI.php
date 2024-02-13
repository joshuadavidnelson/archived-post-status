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
use \WP_CLI\Utils;

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
	 * The maximum number of items that can be processed without a progress bar.
	 *
	 * @since 0.4.0
	 * @var   int
	 */
	protected $count_limit = 20;

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
	 * [--force]
	 * : Only supported public post types with core non-trashed statuses can be archived. Use this flag to skip current status check.
	 *
	 * [--defer-term-counting]
	 * : Recalculate term count in batch, for a performance boost.
	 *
	 * ## EXAMPLES
	 *
	 *     # Archive a post
	 *     wp post archive 123
	 *
	 *     # Archive a post without checking the current status
	 *     wp post archive 123 --force
	 *
	 * @since 0.4.0
	 * @param array $args       The arguments.
	 * @param array $assoc_args The associative arguments.
	 */
	public function archive( $args, $assoc_args ) {

		$status = 0;
		$counting = ( count( $args ) > $this->count_limit );

		if ( $counting ) {
			$progress = Utils\make_progress_bar( "Archiving", count( $args ) );
		}

		foreach ( $args as $obj_id ) {

			$result = $this->handle_action( $obj_id, $assoc_args, 'archive' );

			if ( $counting ) {
				$progress->tick();
			} else {
				$status = $this->success_or_failure( $result );
			}
		}

		if ( $counting ) {
			$progress->finish();
		}

		exit( $status );

	}

	/**
	 * Unarchive a post.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : One or more IDs of posts to delete.
	 *
	 * [--status=<status>]
	 * : Override the new status of the post(s).
	 *
	 * [--defer-term-counting]
	 * : Recalculate term count in batch, for a performance boost.
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
		$counting = ( count( $args ) > $this->count_limit );
		$new_status = Utils\get_flag_value( $assoc_args, 'status', false );

		if ( $counting ) {
			$progress = Utils\make_progress_bar( "Unarchiving", count( $args ) );
		}

		if ( $new_status ) {
			add_filter( 'aps_unarchive_post_status', function() use ( $new_status ) {
				return $new_status;
			} );
		}

		foreach ( $args as $obj_id ) {

			$result = $this->handle_action( $obj_id, $assoc_args, 'unarchive' );

			if ( $counting ) {
				$progress->tick();
			} else {
				$status = $this->success_or_failure( $result );
			}
		}

		if ( $counting ) {
			$progress->finish();
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

		// Force skips the check for the current status of the post.
		$force = Utils\get_flag_value( $assoc_args, 'force', false );
		if ( ! $force && 'archive' === $action ) {

			// Get the archivable statuses.
			// These are statuses that can be archied.
			$archivable_statuses = _aps_get_archivable_statuses();

			// Check that the current status can be archived.
			if ( ! in_array( $status, $archivable_statuses, true ) ) {
				return [ 'error', "Post {$post_id} cannot be archived, '{$status}' is not an archivable status." ];
			}
		}

		// Check that the current status can be unarchived, if that is the action.
		if ( 'unarchive' === $action && 'archive' !== $status ) {
			return [ 'error', "Post {$post_id} cannot be unarchived because it is not in the archive." ];
		}

		if ( Utils\get_flag_value( $assoc_args, 'defer-term-counting' ) ) {
			wp_defer_term_counting( true );
		}

		// Perform the action.
		$function = "aps_{$action}_post";
		if ( ! call_user_func( $function, $post_id ) ) {
			return [ 'error', "Failed to {$action} post {$post_id}." ];
		}

		if ( Utils\get_flag_value( $assoc_args, 'defer-term-counting' ) ) {
			wp_defer_term_counting( false );
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
