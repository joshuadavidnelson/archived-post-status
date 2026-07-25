<?php

namespace ArchivedPostStatus\Hooks;

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
	 * Iterates through all hookable objects, gets their hook descriptors,
	 * and calls the appropriate WordPress registration function.
	 *
	 * @since 0.4.0
	 *
	 * @SuppressWarnings("PHPMD.ElseExpression") -- symmetric action-vs-filter
	 * dispatch; the else is the clearest form for a two-way branch where
	 * both arms call an equivalent WordPress registration function.
	 */
	public function run(): void {
		foreach ( $this->hookables as $hookable ) {
			foreach ( $hookable->hooks() as $descriptor ) {
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
	}

}
