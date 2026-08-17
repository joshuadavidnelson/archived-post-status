<?php
/**
 * ExplainCommand tests.
 *
 * Not a Command subclass, so it is exercised directly against a real
 * RuleChain built from fake RuleProviderInterface implementations — the
 * same technique RuleChainTest uses, since RuleChain is final and cannot be
 * Mockery-doubled. No filter is registered in these tests, so an unhooked
 * apply_filters() passes its value through unchanged, exactly like
 * RuleChainTest's own "no filter registered" tests.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\CLI\ExplainCommand
 */

namespace {
	// Shared WP_CLI in-memory stub + get_flag_value/format_items polyfills.
	require_once __DIR__ . '/Support/WpCliStub.php';

	use ArchivedPostStatus\AutoArchive\ChildMode;
	use ArchivedPostStatus\AutoArchive\Rule;
	use ArchivedPostStatus\AutoArchive\RuleChain;
	use ArchivedPostStatus\AutoArchive\RuleProviderInterface;
	use ArchivedPostStatus\CLI\ExplainCommand;

	/**
	 * @since 0.5.0
	 * @covers ArchivedPostStatus\CLI\ExplainCommand
	 */
	class ExplainCommandTest extends TestCase {

		public function set_up() {
			parent::set_up();
			\WP_CLI::reset();
			global $aps_test_format_items_calls;
			$aps_test_format_items_calls = array();
			\WP_Mock::userFunction( 'absint' )->andReturnUsing( static fn ( $v ) => abs( (int) $v ) );
		}

		/**
		 * @param string $level The level this provider reports.
		 * @param Rule[] $rules What rules_for() always returns.
		 */
		private function fakeProvider( string $level, array $rules ): RuleProviderInterface {
			return new class( $level, $rules ) implements RuleProviderInterface {
				public function __construct( private string $level, private array $rules ) {}

				public function level(): string {
					return $this->level;
				}

				public function rules_for( int $post_id ): array {
					return $this->rules;
				}
			};
		}

		private function stubSupportedPostType( int $post_id, bool $supported = true ): void {
			\WP_Mock::userFunction( 'get_post_type' )->with( $post_id )->andReturn( 'post' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'post' )->andReturn( $supported );
		}

		/**
		 * @covers ArchivedPostStatus\CLI\ExplainCommand::explain
		 */
		public function test_reports_an_error_for_an_unsupported_post_type() {
			\WP_Mock::userFunction( 'get_post_type' )->with( 42 )->andReturn( 'unsupported' );
			\WP_Mock::userFunction( 'aps_is_supported_post_type' )->with( 'unsupported' )->andReturn( false );

			$cmd = new ExplainCommand( new RuleChain( array() ) );
			$cmd->explain( array( 42 ), array() );

			$this->assertCount( 1, \WP_CLI::$errors );
			$this->assertStringContainsString( '42', \WP_CLI::$errors[0] );
			$this->assertStringContainsString( 'not a supported post type', \WP_CLI::$errors[0] );

			global $aps_test_format_items_calls;
			$this->assertSame( array(), $aps_test_format_items_calls, 'format_items must not be reached for an unsupported post type' );
		}

		/**
		 * A post no cascade level says anything about prints a clear message
		 * rather than an empty table, and the outcome is reported as
		 * unscheduled with no frozen-by mention.
		 *
		 * @covers ArchivedPostStatus\CLI\ExplainCommand::explain
		 */
		public function test_a_post_with_no_rule_reports_that_clearly_instead_of_an_empty_table() {
			$this->stubSupportedPostType( 42 );

			$chain = new RuleChain(
				array(
					$this->fakeProvider( 'site', array() ),
					$this->fakeProvider( 'term', array() ),
					$this->fakeProvider( 'post', array() ),
				)
			);

			( new ExplainCommand( $chain ) )->explain( array( 42 ), array() );

			global $aps_test_format_items_calls;
			$this->assertSame( array(), $aps_test_format_items_calls, 'an empty chain must never reach format_items' );

			$this->assertCount( 2, \WP_CLI::$logs );
			$this->assertStringContainsString( 'No cascade level sets a rule', \WP_CLI::$logs[0] );
			$this->assertStringContainsString( 'has no auto-archive rule applied', \WP_CLI::$logs[1] );
			$this->assertStringNotContainsString( 'frozen by', \WP_CLI::$logs[1] );
			$this->assertCount( 0, \WP_CLI::$successes );
		}

		/**
		 * The headline case: prints each level's own contribution AND the
		 * winning level AND the level that froze the rest — the exact
		 * proof phase 13's brief asks for.
		 *
		 * @covers ArchivedPostStatus\CLI\ExplainCommand::explain
		 */
		public function test_prints_the_winning_level_and_the_frozen_by_level() {
			$this->stubSupportedPostType( 42 );

			$chain = new RuleChain(
				array(
					$this->fakeProvider( 'site', array( new Rule( 'site', 12, ChildMode::Open, 'Site default' ) ) ),
					$this->fakeProvider( 'term', array( new Rule( 'term', 3, ChildMode::Locked, 'Category: News' ) ) ),
					$this->fakeProvider( 'post', array() ),
				)
			);

			( new ExplainCommand( $chain ) )->explain( array( 42 ), array() );

			global $aps_test_format_items_calls;
			$this->assertCount( 1, $aps_test_format_items_calls );
			[ $format, $items, $fields ] = $aps_test_format_items_calls[0];

			$this->assertSame( 'table', $format );
			$this->assertSame( array( 'level', 'label', 'days', 'child_mode', 'won', 'froze' ), $fields );
			$this->assertCount( 2, $items );

			$this->assertSame(
				array(
					'level'      => 'site',
					'label'      => 'Site default',
					'days'       => '12',
					'child_mode' => 'open',
					'won'        => '',
					'froze'      => '',
				),
				$items[0]
			);
			$this->assertSame(
				array(
					'level'      => 'term',
					'label'      => 'Category: News',
					'days'       => '3',
					'child_mode' => 'locked',
					'won'        => 'yes',
					'froze'      => 'yes',
				),
				$items[1]
			);

			$this->assertCount( 1, \WP_CLI::$successes );
			$this->assertStringContainsString( '3 day(s)', \WP_CLI::$successes[0] );
			$this->assertStringContainsString( 'Category: News', \WP_CLI::$successes[0] );
			$this->assertStringContainsString( 'term level', \WP_CLI::$successes[0] );
			$this->assertStringContainsString( 'frozen by term', \WP_CLI::$successes[0] );
		}

		/**
		 * A level that freezes the walk but sets no value of its own — e.g.
		 * site set to Off with no days — is reflected in the chain table
		 * (froze=yes, won='' since it supplied nothing) and the "not
		 * scheduled" message names the freezing level.
		 *
		 * @covers ArchivedPostStatus\CLI\ExplainCommand::explain
		 */
		public function test_a_level_that_freezes_without_contributing_a_value_is_shown_and_named() {
			$this->stubSupportedPostType( 42 );

			$chain = new RuleChain(
				array( $this->fakeProvider( 'site', array( new Rule( 'site', null, ChildMode::Off, 'Site default' ) ) ) )
			);

			( new ExplainCommand( $chain ) )->explain( array( 42 ), array() );

			global $aps_test_format_items_calls;
			[ , $items ] = $aps_test_format_items_calls[0];
			$this->assertSame( '—', $items[0]['days'] );
			$this->assertSame( '', $items[0]['won'] );
			$this->assertSame( 'yes', $items[0]['froze'] );

			$this->assertStringContainsString( 'has no auto-archive rule applied', \WP_CLI::$logs[0] );
			$this->assertStringContainsString( 'frozen by site, which sets no value of its own', \WP_CLI::$logs[0] );
		}

		/**
		 * --format is forwarded to Utils\format_items() verbatim.
		 *
		 * @covers ArchivedPostStatus\CLI\ExplainCommand::explain
		 */
		public function test_forwards_the_format_flag() {
			$this->stubSupportedPostType( 42 );

			$chain = new RuleChain(
				array( $this->fakeProvider( 'site', array( new Rule( 'site', 5, ChildMode::Open, 'Site default' ) ) ) )
			);

			( new ExplainCommand( $chain ) )->explain( array( 42 ), array( 'format' => 'json' ) );

			global $aps_test_format_items_calls;
			$this->assertSame( 'json', $aps_test_format_items_calls[0][0] );
		}
	}
}
