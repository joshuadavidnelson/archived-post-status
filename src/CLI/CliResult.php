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
 * Replaces the legacy array('success'|'error', string) tuple shape that
 * predated 0.4.0. Constructed directly via the promoted constructor — the
 * earlier private constructor + named factories were ceremony that prevented
 * no real bug; PHP 8.1 promoted-constructor `public readonly` properties
 * carry the same immutability contract with less code.
 *
 * The class targets the project's PHP 8.1 floor: `readonly` is marked
 * per-property in the promoted constructor (the class-level `readonly`
 * keyword is PHP 8.2+ and is intentionally not used).
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
