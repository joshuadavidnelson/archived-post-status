<?php
/**
 * Hooks\HookLoader Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Hooks\HookLoader
 *
 * Verifies the central hook-registration orchestrator. HookLoader is the
 * only class that may call add_action()/add_filter() directly; these tests
 * pin that contract.
 */

use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Hooks\HookLoader;

/**
 * HookLoader test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Hooks\HookLoader
 */
class HookLoaderTest extends TestCase {

	/**
	 * Build a HookableInterface fixture that returns the given descriptors.
	 *
	 * @param HookDescriptor[] $descriptors
	 */
	private function make_hookable( array $descriptors ): HookableInterface {
		return new class( $descriptors ) implements HookableInterface {
			/** @var HookDescriptor[] */
			private array $descriptors;

			public function __construct( array $descriptors ) {
				$this->descriptors = $descriptors;
			}

			public function hooks(): array {
				return $this->descriptors;
			}
		};
	}

	/**
	 * add() appends the hookable and returns the loader for chaining.
	 *
	 * The "appends" half of the assertion is verified indirectly by
	 * run()-then-WP_Mock-expectation tests below — if add() failed to record
	 * the hookable, those tests' WP_Mock::expectActionAdded() calls would not
	 * be satisfied. Here we lock the fluent-return contract directly.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::add
	 */
	public function test_add_returns_self_for_chaining() {
		$loader   = new HookLoader();
		$hookable = $this->make_hookable( array() );

		$returned = $loader->add( $hookable );

		$this->assertSame( $loader, $returned );
	}

	/**
	 * add_all() returns the loader for chaining. The per-entry registration
	 * is exercised by test_run_iterates_all_registered_hookables() below.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::add_all
	 */
	public function test_add_all_returns_self_for_chaining() {
		$loader = new HookLoader();
		$one    = $this->make_hookable( array() );
		$two    = $this->make_hookable( array() );

		$returned = $loader->add_all( array( $one, $two ) );

		$this->assertSame( $loader, $returned );
	}

	/**
	 * run() calls add_action() for every action descriptor a hookable
	 * declares, with the descriptor's hook name, callback, priority,
	 * and accepted_args.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::run
	 */
	public function test_run_registers_action_descriptors_via_add_action() {
		$callback   = function () {};
		$descriptor = HookDescriptor::action( 'init', $callback, 20, 3 );

		\WP_Mock::expectActionAdded( 'init', $callback, 20, 3 );

		( new HookLoader() )
			->add( $this->make_hookable( array( $descriptor ) ) )
			->run();

		// WP_Mock verifies the expectation during tearDown.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * run() calls add_filter() for every filter descriptor a hookable
	 * declares, with the descriptor's hook name, callback, priority,
	 * and accepted_args.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::run
	 */
	public function test_run_registers_filter_descriptors_via_add_filter() {
		$callback   = function () {};
		$descriptor = HookDescriptor::filter( 'the_title', $callback, 15, 2 );

		\WP_Mock::expectFilterAdded( 'the_title', $callback, 15, 2 );

		( new HookLoader() )
			->add( $this->make_hookable( array( $descriptor ) ) )
			->run();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Mixed bag: a single hookable that returns both an action and a
	 * filter descriptor must route each to the correct registration
	 * function (the is_action() discriminator inside run()).
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::run
	 */
	public function test_run_routes_mixed_action_and_filter_descriptors() {
		$action_cb = function () {};
		$filter_cb = function () {};

		$descriptors = array(
			HookDescriptor::action( 'save_post', $action_cb, 10, 2 ),
			HookDescriptor::filter( 'the_content', $filter_cb, 99, 1 ),
		);

		\WP_Mock::expectActionAdded( 'save_post', $action_cb, 10, 2 );
		\WP_Mock::expectFilterAdded( 'the_content', $filter_cb, 99, 1 );

		( new HookLoader() )
			->add( $this->make_hookable( $descriptors ) )
			->run();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * run() iterates every hookable added to the loader, not just the first.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::run
	 */
	public function test_run_iterates_all_registered_hookables() {
		$cb_one = function () {};
		$cb_two = function () {};

		$one = $this->make_hookable( array( HookDescriptor::action( 'init', $cb_one ) ) );
		$two = $this->make_hookable( array( HookDescriptor::action( 'admin_init', $cb_two ) ) );

		\WP_Mock::expectActionAdded( 'init', $cb_one, 10, 1 );
		\WP_Mock::expectActionAdded( 'admin_init', $cb_two, 10, 1 );

		( new HookLoader() )->add_all( array( $one, $two ) )->run();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A hookable that declares no hooks is a no-op — run() completes
	 * without registering anything. Locks the empty-hooks() contract.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookLoader::run
	 */
	public function test_run_no_ops_for_hookable_with_empty_hooks() {
		$loader = new HookLoader();
		$loader->add( $this->make_hookable( array() ) );

		$loader->run();

		// Nothing to verify on the WP_Mock side; the assertion is that
		// no exception was thrown and run() completed without registering
		// any actions or filters (WP_Mock fails the test if any unexpected
		// add_action/add_filter call fires).
		$this->addToAssertionCount( 1 );
	}
}
