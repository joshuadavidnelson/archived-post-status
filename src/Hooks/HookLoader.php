<?php

namespace ArchivedPostStatus\Hooks;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;

/**
 * Reads HookDescriptor arrays from HookableInterface implementors
 * and registers them with WordPress.
 *
 * This is the only class in the codebase that calls add_action() or add_filter().
 * All hook registration flows through this central orchestrator.
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
	 * Iterates through all hookable objects and registers each one's
	 * descriptors via {@see register()}.
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
	 * `run()` is a single batch pass over the hookables added via add() /
	 * add_all() — the composition root (`Plugin::run()`) calls it exactly
	 * once, on `plugins_loaded`. Some hookables need part of their
	 * registration to happen later, after WordPress state that postdates
	 * `plugins_loaded` has settled — custom post types, which conventionally
	 * register on `init`, are the motivating case (see
	 * `PostList::register_post_type_hooks()` and
	 * `ArchiveColumn::register_post_type_hooks()`, both deferred to
	 * `wp_loaded`). Those callbacks build a second descriptor array and
	 * hand it here directly instead of returning it from hooks(), so this
	 * class stays the sole caller of add_action()/add_filter() even for
	 * registrations that happen outside the batch run() pass.
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
	 * @SuppressWarnings("PHPMD.ElseExpression") -- symmetric action-vs-filter
	 * dispatch; the else is the clearest form for a two-way branch where
	 * both arms call an equivalent WordPress registration function.
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
