<?php
/**
 * Scheduled archive functionality.
 *
 * @link    https://github.com/joshuadavidnelson/archived-post-status
 * @since   0.5.0
 * @package ArchivedPostStatus
 * @license GPL-2.0+
 */

/**
 * Exit if accessed directly, prevent direct access to this file.
 *
 * @since 0.5.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	die;
}

/**
 * Meta key used to mark a post as having a scheduled archive.
 *
 * @since 0.5.0
 */
define( 'APS_SCHEDULER_META_ENABLED', '_aps_scheduled_archive_enabled' );

/**
 * Meta key used to store the scheduled archive timestamp.
 *
 * @since 0.5.0
 */
define( 'APS_SCHEDULER_META_TIMESTAMP', '_aps_scheduled_archive_timestamp' );

/**
 * WP-Cron hook used to archive a post.
 *
 * @since 0.5.0
 */
define( 'APS_SCHEDULER_CRON_HOOK', 'aps_scheduled_archive_post' );

/**
 * Return supported post types for scheduled archive controls.
 *
 * @since 0.5.0
 * @return array<int,string>
 */
function aps_scheduler_get_supported_post_types() {
	$post_types = get_post_types(
		array(
			'public' => true,
		),
		'names'
	);

	$post_types = array_values(
		array_filter(
			$post_types,
			static function ( $post_type ) {
				return ! aps_is_excluded_post_type( $post_type );
			}
		)
	);

	/**
	 * Filter post types that support scheduled archives.
	 *
	 * @since 0.5.0
	 * @param array<int,string> $post_types Supported post type names.
	 * @return array<int,string>
	 */
	return (array) apply_filters( 'aps_scheduler_supported_post_types', $post_types );
}

/**
 * Check if the archive post status is registered.
 *
 * @since 0.5.0
 * @return bool
 */
function aps_scheduler_archive_status_exists() {
	return null !== get_post_status_object( aps_post_status_slug() );
}

/**
 * Register scheduled archive meta boxes.
 *
 * @since 0.5.0
 * @return void
 */
function aps_scheduler_register_meta_boxes() {
	foreach ( aps_scheduler_get_supported_post_types() as $post_type ) {
		add_meta_box(
			'aps-scheduled-archive',
			esc_html__( 'Scheduled Archive', 'archived-post-status' ),
			'aps_scheduler_render_meta_box',
			$post_type,
			'side',
			'default'
		);
	}
}
add_action( 'add_meta_boxes', 'aps_scheduler_register_meta_boxes' );

/**
 * Get schedule parts for a post.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return array<string,mixed>
 */
function aps_scheduler_get_schedule_parts( $post_id ) {
	$post_id   = absint( $post_id );
	$enabled   = '1' === (string) get_post_meta( $post_id, APS_SCHEDULER_META_ENABLED, true );
	$timestamp = absint( get_post_meta( $post_id, APS_SCHEDULER_META_TIMESTAMP, true ) );
	$date      = '';
	$hour      = 12;
	$minute    = 0;
	$meridiem  = 'AM';

	if ( $timestamp > 0 ) {
		$date     = wp_date( 'Y-m-d', $timestamp );
		$hour_24  = absint( wp_date( 'G', $timestamp ) );
		$minute   = absint( wp_date( 'i', $timestamp ) );
		$meridiem = $hour_24 >= 12 ? 'PM' : 'AM';
		$hour     = $hour_24 % 12;
		$hour     = 0 === $hour ? 12 : $hour;
	}

	return array(
		'enabled'   => $enabled,
		'timestamp' => $timestamp,
		'date'      => $date,
		'hour'      => $hour,
		'minute'    => $minute,
		'meridiem'  => $meridiem,
	);
}

/**
 * Render the scheduled archive meta box.
 *
 * @since 0.5.0
 * @param WP_Post $post Post object.
 * @return void
 */
function aps_scheduler_render_meta_box( $post ) {
	$parts = aps_scheduler_get_schedule_parts( $post->ID );

	wp_nonce_field( 'aps_scheduler_save_' . $post->ID, 'aps_scheduler_nonce' );
	?>
	<div class="aps-scheduler-ui">
		<p>
			<label>
				<input type="checkbox" name="aps_scheduler_enabled" value="1" <?php checked( $parts['enabled'] ); ?> />
				<?php esc_html_e( 'Enable scheduled archive', 'archived-post-status' ); ?>
			</label>
		</p>

		<p>
			<label for="aps_scheduler_date"><?php esc_html_e( 'Date', 'archived-post-status' ); ?></label><br />
			<input type="date" id="aps_scheduler_date" name="aps_scheduler_date" value="<?php echo esc_attr( $parts['date'] ); ?>" />
		</p>

		<p>
			<label><?php esc_html_e( 'Time', 'archived-post-status' ); ?></label><br />
			<input type="text" name="aps_scheduler_hour" value="<?php echo esc_attr( sprintf( '%02d', absint( $parts['hour'] ) ) ); ?>" inputmode="numeric" pattern="[0-9]*" size="2" maxlength="2" aria-label="<?php esc_attr_e( 'Hour', 'archived-post-status' ); ?>" />
			:
			<input type="text" name="aps_scheduler_minute" value="<?php echo esc_attr( sprintf( '%02d', absint( $parts['minute'] ) ) ); ?>" inputmode="numeric" pattern="[0-9]*" size="2" maxlength="2" aria-label="<?php esc_attr_e( 'Minute', 'archived-post-status' ); ?>" />
			<label><input type="radio" name="aps_scheduler_meridiem" value="AM" <?php checked( 'AM', $parts['meridiem'] ); ?> /> <?php esc_html_e( 'AM', 'archived-post-status' ); ?></label>
			<label><input type="radio" name="aps_scheduler_meridiem" value="PM" <?php checked( 'PM', $parts['meridiem'] ); ?> /> <?php esc_html_e( 'PM', 'archived-post-status' ); ?></label>
		</p>

		<?php if ( ! empty( $parts['timestamp'] ) ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s scheduled archive date/time. */
					esc_html__( 'Scheduled to archive on %s.', 'archived-post-status' ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), absint( $parts['timestamp'] ) ) )
				);
				?>
			</p>
			<p>
				<button type="button" class="button aps-scheduler-clear-button"><?php esc_html_e( 'Clear scheduled archive', 'archived-post-status' ); ?></button>
			</p>
		<?php endif; ?>

		<input type="hidden" name="aps_scheduler_clear" value="0" />
	</div>
	<?php
}

/**
 * Save scheduled archive data from post edit, Quick Edit, or Bulk Edit.
 *
 * @since 0.5.0
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @return void
 */
function aps_scheduler_save_post( $post_id, $post ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! in_array( $post->post_type, aps_scheduler_get_supported_post_types(), true ) ) {
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Nonce handled below depending on edit context.
	$is_bulk = isset( $_REQUEST['aps_scheduler_bulk_action'] );

	if ( $is_bulk ) {
		aps_scheduler_save_bulk_post( $post_id );
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked immediately below.
	$nonce = isset( $_POST['aps_scheduler_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['aps_scheduler_nonce'] ) ) : '';

	if ( ! wp_verify_nonce( $nonce, 'aps_scheduler_save_' . $post_id ) ) {
		return;
	}

	aps_scheduler_save_single_post( $post_id );
}
add_action( 'save_post', 'aps_scheduler_save_post', 20, 2 );

/**
 * Save scheduled archive data from the single post edit screen.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_save_single_post( $post_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in aps_scheduler_save_post().
	$clear = isset( $_POST['aps_scheduler_clear'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['aps_scheduler_clear'] ) );

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in aps_scheduler_save_post().
	$enabled = isset( $_POST['aps_scheduler_enabled'] );

	if ( $clear || ! $enabled ) {
		aps_scheduler_clear_schedule( $post_id );
		return;
	}

	aps_scheduler_save_schedule_from_request( $post_id );
}

/**
 * Save scheduled archive data from Bulk Edit.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_save_bulk_post( $post_id ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Bulk edit nonce is handled by WordPress core.
	$action = isset( $_REQUEST['aps_scheduler_bulk_action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['aps_scheduler_bulk_action'] ) ) : 'no_change';

	if ( 'no_change' === $action || '' === $action ) {
		return;
	}

	if ( 'clear' === $action ) {
		aps_scheduler_clear_schedule( $post_id );
		return;
	}

	if ( 'set' === $action ) {
		aps_scheduler_save_schedule_from_request( $post_id );
	}
}

/**
 * Save the schedule from request data.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_save_schedule_from_request( $post_id ) {
	if ( ! aps_scheduler_archive_status_exists() ) {
		aps_scheduler_clear_schedule( $post_id );
		return;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Verified by caller or WordPress core bulk edit flow.
	$date = isset( $_REQUEST['aps_scheduler_date'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['aps_scheduler_date'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Verified by caller or WordPress core bulk edit flow.
	$hour = isset( $_REQUEST['aps_scheduler_hour'] ) ? absint( $_REQUEST['aps_scheduler_hour'] ) : 12;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Verified by caller or WordPress core bulk edit flow.
	$minute = isset( $_REQUEST['aps_scheduler_minute'] ) ? absint( $_REQUEST['aps_scheduler_minute'] ) : 0;
	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- Verified by caller or WordPress core bulk edit flow.
	$meridiem = isset( $_REQUEST['aps_scheduler_meridiem'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_REQUEST['aps_scheduler_meridiem'] ) ) ) : 'AM';

	if ( '' === $date ) {
		aps_scheduler_add_user_notice( __( 'Please select a scheduled archive date.', 'archived-post-status' ) );
		return;
	}

	if ( $hour < 1 || $hour > 12 ) {
		$hour = 12;
	}

	if ( $minute > 59 ) {
		$minute = 0;
	}

	if ( ! in_array( $meridiem, array( 'AM', 'PM' ), true ) ) {
		$meridiem = 'AM';
	}

	$hour_24 = $hour % 12;
	if ( 'PM' === $meridiem ) {
		$hour_24 += 12;
	}

	$timezone = wp_timezone();
	$date_raw = sprintf( '%s %02d:%02d:00', $date, $hour_24, $minute );
	$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date_raw, $timezone );

	if ( ! $datetime ) {
		aps_scheduler_add_user_notice( __( 'Please select a valid scheduled archive date and time.', 'archived-post-status' ) );
		return;
	}

	$timestamp = $datetime->getTimestamp();

	if ( $timestamp <= time() ) {
		aps_scheduler_add_user_notice( __( 'Scheduled archive date must be in the future.', 'archived-post-status' ) );
		return;
	}

	aps_scheduler_unschedule( $post_id );
	update_post_meta( $post_id, APS_SCHEDULER_META_ENABLED, '1' );
	update_post_meta( $post_id, APS_SCHEDULER_META_TIMESTAMP, $timestamp );
	wp_schedule_single_event( $timestamp, APS_SCHEDULER_CRON_HOOK, array( absint( $post_id ) ) );
}

/**
 * Clear a scheduled archive.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_clear_schedule( $post_id ) {
	$post_id = absint( $post_id );
	aps_scheduler_unschedule( $post_id );
	delete_post_meta( $post_id, APS_SCHEDULER_META_ENABLED );
	delete_post_meta( $post_id, APS_SCHEDULER_META_TIMESTAMP );
}

/**
 * Unschedule archive events for a post.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_unschedule( $post_id ) {
	$post_id = absint( $post_id );

	while ( $timestamp = wp_next_scheduled( APS_SCHEDULER_CRON_HOOK, array( $post_id ) ) ) {
		wp_unschedule_event( $timestamp, APS_SCHEDULER_CRON_HOOK, array( $post_id ) );
	}
}

/**
 * Archive a post via WP-Cron.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_archive_post( $post_id ) {
	$post_id = absint( $post_id );
	$post    = get_post( $post_id );

	if ( ! $post ) {
		aps_scheduler_clear_schedule( $post_id );
		return;
	}

	if ( ! aps_scheduler_archive_status_exists() ) {
		return;
	}

	$timestamp = absint( get_post_meta( $post_id, APS_SCHEDULER_META_TIMESTAMP, true ) );

	if ( ! $timestamp || $timestamp > time() ) {
		return;
	}

	if ( in_array( $post->post_status, array( aps_post_status_slug(), 'trash', 'auto-draft' ), true ) ) {
		aps_scheduler_clear_schedule( $post_id );
		return;
	}

	$result = wp_update_post(
		array(
			'ID'          => $post_id,
			'post_status' => aps_post_status_slug(),
		),
		true
	);

	if ( is_wp_error( $result ) ) {
		return;
	}

	aps_scheduler_clear_schedule( $post_id );
	aps_scheduler_send_archive_email( $post_id );
}
add_action( APS_SCHEDULER_CRON_HOOK, 'aps_scheduler_archive_post' );

/**
 * Send an email notification after a post is archived.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_send_archive_email( $post_id ) {
	$send_email = (bool) apply_filters( 'aps_scheduler_send_archive_email', true, $post_id );

	if ( ! $send_email ) {
		return;
	}

	$to = get_option( 'admin_email' );

	if ( ! $to ) {
		return;
	}

	$title = wp_strip_all_tags( get_the_title( $post_id ) );

	if ( ! $title ) {
		$title = sprintf(
			/* translators: %d: Post ID. */
			__( 'Post #%d', 'archived-post-status' ),
			$post_id
		);
	}

	$subject = sprintf(
		/* translators: %s: Post title. */
		__( 'Post archived: %s', 'archived-post-status' ),
		$title
	);

	$message  = sprintf(
		/* translators: %s: Post title. */
		__( 'Your post "%s" has been archived.', 'archived-post-status' ),
		$title
	);
	$message .= "\n\n" . get_edit_post_link( $post_id, 'raw' );

	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- Sends one admin notification after one scheduled archive event runs.
	wp_mail( $to, $subject, $message );
}

/**
 * Clear scheduled archive when a post is deleted or trashed.
 *
 * @since 0.5.0
 * @param int $post_id Post ID.
 * @return void
 */
function aps_scheduler_unschedule_on_delete( $post_id ) {
	aps_scheduler_clear_schedule( absint( $post_id ) );
}
add_action( 'before_delete_post', 'aps_scheduler_unschedule_on_delete' );
add_action( 'trashed_post', 'aps_scheduler_unschedule_on_delete' );

/**
 * Register Scheduled Archive list table columns.
 *
 * @since 0.5.0
 * @return void
 */
function aps_scheduler_register_columns() {
	foreach ( aps_scheduler_get_supported_post_types() as $post_type ) {
		add_filter( "manage_{$post_type}_posts_columns", 'aps_scheduler_add_column' );
		add_action( "manage_{$post_type}_posts_custom_column", 'aps_scheduler_render_column', 10, 2 );
	}
}
add_action( 'admin_init', 'aps_scheduler_register_columns' );

/**
 * Add Scheduled Archive column.
 *
 * @since 0.5.0
 * @param array<string,string> $columns Columns.
 * @return array<string,string>
 */
function aps_scheduler_add_column( $columns ) {
	$columns['aps_scheduled_archive'] = __( 'Scheduled Archive', 'archived-post-status' );
	return $columns;
}

/**
 * Render Scheduled Archive column.
 *
 * @since 0.5.0
 * @param string $column  Column name.
 * @param int    $post_id Post ID.
 * @return void
 */
function aps_scheduler_render_column( $column, $post_id ) {
	if ( 'aps_scheduled_archive' !== $column ) {
		return;
	}

	$parts = aps_scheduler_get_schedule_parts( $post_id );

	if ( empty( $parts['timestamp'] ) ) {
		echo '&mdash;';
	} else {
		$format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		echo esc_html( wp_date( $format, absint( $parts['timestamp'] ) ) );
	}

	echo '<span class="aps-scheduler-inline-data" style="display:none"'
		. ' data-enabled="' . esc_attr( $parts['enabled'] ? '1' : '0' ) . '"'
		. ' data-date="' . esc_attr( $parts['date'] ) . '"'
		. ' data-hour="' . esc_attr( sprintf( '%02d', absint( $parts['hour'] ) ) ) . '"'
		. ' data-minute="' . esc_attr( sprintf( '%02d', absint( $parts['minute'] ) ) ) . '"'
		. ' data-meridiem="' . esc_attr( $parts['meridiem'] ) . '"'
		. '></span>';
}

/**
 * Register Quick Edit and Bulk Edit boxes.
 *
 * @since 0.5.0
 * @return void
 */
function aps_scheduler_register_inline_boxes() {
	foreach ( aps_scheduler_get_supported_post_types() as $post_type ) {
		add_action( 'quick_edit_custom_box', 'aps_scheduler_quick_edit_box', 10, 2 );
		add_action( 'bulk_edit_custom_box', 'aps_scheduler_bulk_edit_box', 10, 2 );
		break;
	}
}
add_action( 'admin_init', 'aps_scheduler_register_inline_boxes' );

/**
 * Render Quick Edit controls.
 *
 * @since 0.5.0
 * @param string $column_name Column name.
 * @param string $post_type   Post type.
 * @return void
 */
function aps_scheduler_quick_edit_box( $column_name, $post_type ) {
	if ( 'aps_scheduled_archive' !== $column_name || ! in_array( $post_type, aps_scheduler_get_supported_post_types(), true ) ) {
		return;
	}
	?>
	<fieldset class="inline-edit-col-right">
		<div class="inline-edit-col aps-scheduler-ui aps-scheduler-ui-quick">
			<span class="title"><?php esc_html_e( 'Scheduled Archive', 'archived-post-status' ); ?></span>
			<label><input type="checkbox" name="aps_scheduler_enabled" value="1" /> <?php esc_html_e( 'Enable scheduled archive', 'archived-post-status' ); ?></label>
			<label><?php esc_html_e( 'Date', 'archived-post-status' ); ?> <input type="date" name="aps_scheduler_date" value="" /></label>
			<label><?php esc_html_e( 'Time', 'archived-post-status' ); ?> <input type="text" name="aps_scheduler_hour" value="12" size="2" maxlength="2" inputmode="numeric" />:<input type="text" name="aps_scheduler_minute" value="00" size="2" maxlength="2" inputmode="numeric" /></label>
			<label><input type="radio" name="aps_scheduler_meridiem" value="AM" checked="checked" /> <?php esc_html_e( 'AM', 'archived-post-status' ); ?></label>
			<label><input type="radio" name="aps_scheduler_meridiem" value="PM" /> <?php esc_html_e( 'PM', 'archived-post-status' ); ?></label>
			<button type="button" class="button aps-scheduler-clear-button"><?php esc_html_e( 'Clear scheduled archive', 'archived-post-status' ); ?></button>
			<input type="hidden" name="aps_scheduler_clear" value="0" />
		</div>
	</fieldset>
	<?php
}

/**
 * Render Bulk Edit controls.
 *
 * @since 0.5.0
 * @param string $column_name Column name.
 * @param string $post_type   Post type.
 * @return void
 */
function aps_scheduler_bulk_edit_box( $column_name, $post_type ) {
	if ( 'aps_scheduled_archive' !== $column_name || ! in_array( $post_type, aps_scheduler_get_supported_post_types(), true ) ) {
		return;
	}
	?>
	<fieldset class="inline-edit-col-right">
		<div class="inline-edit-col aps-scheduler-ui aps-scheduler-ui-bulk">
			<span class="title"><?php esc_html_e( 'Scheduled Archive', 'archived-post-status' ); ?></span>
			<label>
				<?php esc_html_e( 'Action', 'archived-post-status' ); ?>
				<select name="aps_scheduler_bulk_action">
					<option value="no_change"><?php esc_html_e( 'No change', 'archived-post-status' ); ?></option>
					<option value="set"><?php esc_html_e( 'Set scheduled archive', 'archived-post-status' ); ?></option>
					<option value="clear"><?php esc_html_e( 'Clear scheduled archive', 'archived-post-status' ); ?></option>
				</select>
			</label>
			<label><?php esc_html_e( 'Date', 'archived-post-status' ); ?> <input type="date" name="aps_scheduler_date" value="" /></label>
			<label><?php esc_html_e( 'Time', 'archived-post-status' ); ?> <input type="text" name="aps_scheduler_hour" value="12" size="2" maxlength="2" inputmode="numeric" />:<input type="text" name="aps_scheduler_minute" value="00" size="2" maxlength="2" inputmode="numeric" /></label>
			<label><input type="radio" name="aps_scheduler_meridiem" value="AM" checked="checked" /> <?php esc_html_e( 'AM', 'archived-post-status' ); ?></label>
			<label><input type="radio" name="aps_scheduler_meridiem" value="PM" /> <?php esc_html_e( 'PM', 'archived-post-status' ); ?></label>
		</div>
	</fieldset>
	<?php
}

/**
 * Add a user-scoped admin notice.
 *
 * @since 0.5.0
 * @param string $message Notice message.
 * @param string $type    Notice type.
 * @return void
 */
function aps_scheduler_add_user_notice( $message, $type = 'error' ) {
	$user_id = get_current_user_id();

	if ( $user_id ) {
		set_transient(
			'aps_scheduler_notice_' . $user_id,
			array(
				'message' => wp_strip_all_tags( $message ),
				'type'    => sanitize_key( $type ),
			),
			60
		);
	}
}

/**
 * Print user-scoped admin notices.
 *
 * @since 0.5.0
 * @return void
 */
function aps_scheduler_print_user_notice() {
	$user_id = get_current_user_id();

	if ( ! $user_id ) {
		return;
	}

	$notice = get_transient( 'aps_scheduler_notice_' . $user_id );

	if ( ! $notice || empty( $notice['message'] ) ) {
		return;
	}

	delete_transient( 'aps_scheduler_notice_' . $user_id );

	$type = ! empty( $notice['type'] ) ? sanitize_html_class( $notice['type'] ) : 'error';

	echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $notice['message'] ) . '</p></div>';
}
add_action( 'admin_notices', 'aps_scheduler_print_user_notice' );

/**
 * Print scheduler admin scripts.
 *
 * @since 0.5.0
 * @return void
 */
function aps_scheduler_print_admin_scripts() {
	$screen = get_current_screen();

	if ( ! $screen || ! in_array( $screen->post_type, aps_scheduler_get_supported_post_types(), true ) ) {
		return;
	}
	?>
	<script>
	(function() {
		function clampTwoDigitField(field, min, max, fallback) {
			if (!field) { return fallback; }
			var value = String(field.value || '').replace(/\D/g, '');
			if (value === '') { value = fallback; }
			value = parseInt(value, 10);
			if (isNaN(value)) { value = fallback; }
			if (value < min) { value = min; }
			if (value > max) { value = max; }
			field.value = String(value).padStart(2, '0');
			return value;
		}

		function stepTwoDigitField(field, min, max, fallback, direction) {
			var value = clampTwoDigitField(field, min, max, fallback) + direction;
			if (value < min) { value = max; }
			if (value > max) { value = min; }
			field.value = String(value).padStart(2, '0');
		}

		document.addEventListener('input', function(event) {
			if (event.target && (event.target.name === 'aps_scheduler_hour' || event.target.name === 'aps_scheduler_minute')) {
				event.target.value = event.target.value.replace(/\D/g, '').slice(0, 2);
			}
		});

		document.addEventListener('blur', function(event) {
			if (!event.target) { return; }
			if (event.target.name === 'aps_scheduler_hour') { clampTwoDigitField(event.target, 1, 12, 12); }
			if (event.target.name === 'aps_scheduler_minute') { clampTwoDigitField(event.target, 0, 59, 0); }
		}, true);

		document.addEventListener('keydown', function(event) {
			if (!event.target || (event.key !== 'ArrowUp' && event.key !== 'ArrowDown')) { return; }
			if (event.target.name === 'aps_scheduler_hour') {
				event.preventDefault();
				stepTwoDigitField(event.target, 1, 12, 12, event.key === 'ArrowUp' ? 1 : -1);
			}
			if (event.target.name === 'aps_scheduler_minute') {
				event.preventDefault();
				stepTwoDigitField(event.target, 0, 59, 0, event.key === 'ArrowUp' ? 1 : -1);
			}
		});

		document.addEventListener('click', function(event) {
			if (!event.target || !event.target.classList.contains('aps-scheduler-clear-button')) { return; }
			var wrapper = event.target.closest('.aps-scheduler-ui');
			if (!wrapper) { return; }
			var enabled = wrapper.querySelector('[name="aps_scheduler_enabled"]');
			var date = wrapper.querySelector('[name="aps_scheduler_date"]');
			var hour = wrapper.querySelector('[name="aps_scheduler_hour"]');
			var minute = wrapper.querySelector('[name="aps_scheduler_minute"]');
			var am = wrapper.querySelector('[name="aps_scheduler_meridiem"][value="AM"]');
			var clearValue = wrapper.querySelector('[name="aps_scheduler_clear"]');
			if (enabled) { enabled.checked = false; }
			if (date) { date.value = ''; }
			if (hour) { hour.value = '12'; }
			if (minute) { minute.value = '00'; }
			if (am) { am.checked = true; }
			if (clearValue) { clearValue.value = '1'; }
		});

		if (window.inlineEditPost && window.inlineEditPost.edit) {
			var originalEdit = window.inlineEditPost.edit;
			window.inlineEditPost.edit = function(id) {
				originalEdit.apply(this, arguments);
				var postId = typeof id === 'object' ? parseInt(this.getId(id), 10) : parseInt(id, 10);
				if (!postId) { return; }
				var row = document.getElementById('post-' + postId);
				var editRow = document.getElementById('edit-' + postId);
				if (!row || !editRow) { return; }
				var data = row.querySelector('.aps-scheduler-inline-data');
				var wrapper = editRow.querySelector('.aps-scheduler-ui-quick');
				if (!data || !wrapper) { return; }
				var enabled = wrapper.querySelector('[name="aps_scheduler_enabled"]');
				var date = wrapper.querySelector('[name="aps_scheduler_date"]');
				var hour = wrapper.querySelector('[name="aps_scheduler_hour"]');
				var minute = wrapper.querySelector('[name="aps_scheduler_minute"]');
				var meridiem = wrapper.querySelector('[name="aps_scheduler_meridiem"][value="' + (data.getAttribute('data-meridiem') || 'AM') + '"]');
				var clearValue = wrapper.querySelector('[name="aps_scheduler_clear"]');
				if (enabled) { enabled.checked = data.getAttribute('data-enabled') === '1'; }
				if (date) { date.value = data.getAttribute('data-date') || ''; }
				if (hour) { hour.value = data.getAttribute('data-hour') || '12'; }
				if (minute) { minute.value = data.getAttribute('data-minute') || '00'; }
				if (meridiem) { meridiem.checked = true; }
				if (clearValue) { clearValue.value = '0'; }
			};
		}
	})();
	</script>
	<?php
}
add_action( 'admin_footer-post.php', 'aps_scheduler_print_admin_scripts' );
add_action( 'admin_footer-post-new.php', 'aps_scheduler_print_admin_scripts' );
add_action( 'admin_footer-edit.php', 'aps_scheduler_print_admin_scripts' );
