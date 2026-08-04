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
 * `readonly` is marked per-property because the class-level keyword is PHP
 * 8.2+ and this plugin's floor is 8.1.
 *
 * @since 0.4.0
 */
final class CliResult {

	/**
	 * @param bool   $is_success Whether the operation succeeded.
	 * @param string $message    Human-readable message describing the outcome.
	 */
	public function __construct(
		public readonly bool $is_success,
		public readonly string $message,
	) {}
}
