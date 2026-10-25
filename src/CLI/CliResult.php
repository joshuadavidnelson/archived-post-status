<?php
/**
 * Value object describing the outcome of a CLI sub-action.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Immutable success/error wrapper returned by Command::run().
 *
 * @since 0.4.0
 */
final readonly class CliResult {

	/**
	 * @param bool   $is_success Whether the operation succeeded.
	 * @param string $message    Human-readable message describing the outcome.
	 */
	public function __construct(
		public bool $is_success,
		public string $message,
	) {}
}
