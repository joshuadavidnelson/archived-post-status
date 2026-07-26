<?php
/**
 * Hooks\HookDescriptor Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Hooks\HookDescriptor
 *
 * Pure structural tests for the readonly value object. No WordPress globals.
 * Verifies the named-constructor contract (HookDescriptor::action / ::filter),
 * the is_action() discriminator, default arg values, and immutability.
 */

use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * HookDescriptor test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Hooks\HookDescriptor
 */
class HookDescriptorTest extends TestCase {

	/**
	 * HookDescriptor::action() captures hook name, callback, priority,
	 * and accepted_args as readonly properties, and tags the type as 'action'.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::action
	 */
	public function test_action_named_constructor_sets_all_properties() {
		$callback = function () {};

		$descriptor = HookDescriptor::action( 'init', $callback, 20, 3 );

		$this->assertSame( HookDescriptor::TYPE_ACTION, $descriptor->type );
		$this->assertSame( 'init', $descriptor->hook );
		$this->assertSame( $callback, $descriptor->callback );
		$this->assertSame( 20, $descriptor->priority );
		$this->assertSame( 3, $descriptor->accepted_args );
	}

	/**
	 * HookDescriptor::filter() mirrors action() but tags the type as 'filter'.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::filter
	 */
	public function test_filter_named_constructor_sets_all_properties() {
		$callback = function () {};

		$descriptor = HookDescriptor::filter( 'the_title', $callback, 15, 2 );

		$this->assertSame( HookDescriptor::TYPE_FILTER, $descriptor->type );
		$this->assertSame( 'the_title', $descriptor->hook );
		$this->assertSame( $callback, $descriptor->callback );
		$this->assertSame( 15, $descriptor->priority );
		$this->assertSame( 2, $descriptor->accepted_args );
	}

	/**
	 * is_action() returns true for action descriptors — the discriminator
	 * HookLoader uses to choose between add_action() and add_filter().
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::is_action
	 */
	public function test_is_action_returns_true_for_action_descriptor() {
		$descriptor = HookDescriptor::action( 'init', function () {} );

		$this->assertTrue( $descriptor->is_action() );
	}

	/**
	 * is_action() returns false for filter descriptors.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::is_action
	 */
	public function test_is_action_returns_false_for_filter_descriptor() {
		$descriptor = HookDescriptor::filter( 'the_title', function () {} );

		$this->assertFalse( $descriptor->is_action() );
	}

	/**
	 * Default priority is 10 (matches WordPress's add_action/add_filter defaults).
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::action
	 */
	public function test_action_defaults_priority_to_10() {
		$descriptor = HookDescriptor::action( 'init', function () {} );

		$this->assertSame( 10, $descriptor->priority );
	}

	/**
	 * Default accepted_args is 1 (matches WordPress's add_action/add_filter defaults).
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::action
	 */
	public function test_action_defaults_accepted_args_to_1() {
		$descriptor = HookDescriptor::action( 'init', function () {} );

		$this->assertSame( 1, $descriptor->accepted_args );
	}

	/**
	 * Filter named-constructor also defaults priority and accepted_args.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::filter
	 */
	public function test_filter_defaults_priority_and_accepted_args() {
		$descriptor = HookDescriptor::filter( 'the_title', function () {} );

		$this->assertSame( 10, $descriptor->priority );
		$this->assertSame( 1, $descriptor->accepted_args );
	}

	/**
	 * The class is declared readonly — assigning to a property after
	 * construction throws \Error. Locks the value-object contract.
	 *
	 * @covers ArchivedPostStatus\Hooks\HookDescriptor::action
	 */
	public function test_descriptor_properties_are_immutable() {
		$descriptor = HookDescriptor::action( 'init', function () {} );

		$this->expectException( \Error::class );

		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$descriptor->hook = 'something_else';
	}
}
