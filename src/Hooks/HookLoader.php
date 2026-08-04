<?php

namespace ArchivedPostStatus\Hooks;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;

/**
 * Reads HookDescriptor arrays from HookableInterface implementors
 * and registers them with WordPress.
 *
 * Classes that call add_action()/add_filter() directly are doing so for hooks
 * that are conditional or short-lived rather than standing registrations —
 * ArchiveColumnSort's sort filters, BulkActionHandler and Registrar's bracketed
 * undo/status overrides, PostStatusGuard's recursion guard. Those are
 * deliberate exceptions, not gaps.
 *
 * @since 0.4.0
 */
final class HookLoader {

	/**
	 * Collection of hookable objects to register.
	 *
	 * @since 0.4.0
	 * @var HookableInterface[]
	 */
	private array $hookables = array();

	/**
	 * Add a hookable object to be registered.
	 *
	 * @since 0.4.0
	 * @param HookableInterface $hookable Object that declares WordPress hooks.
	 * @return self For method chaining.
	 */
	public function add( HookableInterface $hookable ): self {
		$this->hookables[] = $hookable;
		return $this;
	}

	/**
	 * Add multiple hookables at once.
	 *
	 * @since 0.4.0
	 * @param HookableInterface[] $hookables Array of hookable objects.
	 * @return self For method chaining.
	 */
	public function add_all( array $hookables ): self {
		foreach ( $hookables as $hookable ) {
			$this->add( $hookable );
		}
		return $this;
	}

	/**
	 * Register all hooks from all added hookables.
	 *
	 * @since 0.4.0
	 * @return void
	 */
	public function run(): void {
		foreach ( $this->hookables as $hookable ) {
			$this->register( $hookable->hooks() );
		}
	}

	/**
	 * Register a batch of hook descriptors immediately.
	 *
	 * `run()` is a single batch pass on `plugins_loaded`. Hookables whose
	 * registration depends on later WordPress state — custom post types, which
	 * register on `init` — build a second descriptor array and hand it here
	 * directly, keeping this class the sole caller of add_action()/add_filter().
	 *
	 * @since 0.4.0
	 * @param HookDescriptor[] $descriptors
	 * @return void
	 */
	public function register( array $descriptors ): void {
		foreach ( $descriptors as $descriptor ) {
			$this->register_descriptor( $descriptor );
		}
	}

	/**
	 * Dispatch a single descriptor to add_action() or add_filter().
	 *
	 * @since 0.4.0
	 * @param HookDescriptor $descriptor
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression") -- symmetric action-vs-filter dispatch.
	 */
	private function register_descriptor( HookDescriptor $descriptor ): void {
		if ( $descriptor->is_action() ) {
			add_action(
				$descriptor->hook,
				$descriptor->callback,
				$descriptor->priority,
				$descriptor->accepted_args
			);
		} else {
			add_filter(
				$descriptor->hook,
				$descriptor->callback,
				$descriptor->priority,
				$descriptor->accepted_args
			);
		}
	}

}
