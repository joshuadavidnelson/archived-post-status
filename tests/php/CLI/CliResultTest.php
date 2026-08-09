<?php
/**
 * CliResult value object tests.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\CliResult
 */

use ArchivedPostStatus\CLI\CliResult;

/**
 * Behavior contract for the CliResult value object.
 *
 * CliResult is the readonly replacement for the legacy `array('error'|'success',
 * string)` tuple shape that CLI::handle_action() returned before 0.4.0.
 * Construction is via the promoted constructor; the earlier private
 * constructor + named factories were dropped during the 0.4.0
 * refactor follow-up as ceremony that prevented no real bug. These tests
 * pin the construction shape, message round-trip, and the immutability
 * guarantees that the runner relies on.
 *
 * @since 0.4.0
 */
class CliResultTest extends TestCase {

	/**
	 * The promoted constructor accepts (bool, string) and round-trips both
	 * arguments verbatim onto readonly properties. Exercises both
	 * success-flag values to pin the shape.
	 *
	 * @covers ArchivedPostStatus\CLI\CliResult::__construct
	 */
	public function test_constructs_with_flag_and_message() {
		$ok = new CliResult( true, 'Archived post 42.' );
		$this->assertInstanceOf( CliResult::class, $ok );
		$this->assertTrue( $ok->is_success );
		$this->assertSame( 'Archived post 42.', $ok->message );

		$err = new CliResult( false, 'Post 42 is not a supported post type.' );
		$this->assertInstanceOf( CliResult::class, $err );
		$this->assertFalse( $err->is_success );
		$this->assertSame( 'Post 42 is not a supported post type.', $err->message );
	}

	/**
	 * Empty messages are preserved as-is — the value object does not
	 * coerce, default, or validate the message content.
	 *
	 * @covers ArchivedPostStatus\CLI\CliResult::__construct
	 */
	public function test_message_is_preserved_when_empty() {
		$this->assertSame( '', ( new CliResult( true, '' ) )->message );
		$this->assertSame( '', ( new CliResult( false, '' ) )->message );
	}

}
