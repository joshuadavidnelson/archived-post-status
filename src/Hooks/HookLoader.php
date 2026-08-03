<?php

namespace ArchivedPostStatus\Hooks;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Contracts\HookableInterface;

/**
 * Reads HookDescriptor arrays from HookableInterface implementors
 * and registers them with WordPress.
 *
 * The central orchestrator for the plugin's standing hooks: every
 * HookableInterface implementor declares its hooks() array once, and this
 * class is what turns those descriptors into add_action()/add_filter()
 * calls — see {@see run()} and {@see register()}.
 *
 * A handful of classes still call add_action()/add_filter() directly, for
 * hooks that are conditional or short-lived rather than standing
 * registrations: {@see \ArchivedPostStatus\Admin\ArchiveColumn::handle_sort()}'s
 * sort filters (added only while the archived-column sort query is active),
 * {@see \ArchivedPostStatus\Admin\BulkActionHandler::bulk_unarchive()} and
 * {@see \ArchivedPostStatus\CLI\Registrar::unarchive()}'s undo/status-override
 * filters (bracketed add/remove around a single dispatch), and
 * {@see \ArchivedPostStatus\Status\PostStatusGuard::enforce_archive_state()}'s
 * recursion guard (removing and re-adding itself around its own
 * wp_update_post() call). Those are deliberate exceptions to the pattern
 * this class exists to centralize, not gaps in it.
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
