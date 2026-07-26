<?php

namespace ArchivedPostStatus\Contracts;

use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Any class that registers WordPress hooks must implement this interface.
 *
 * Classes implementing this interface declare their hooks as data rather than
 * registering them imperatively. A HookLoader reads these declarations and
 * performs the actual WordPress registration.
 *
 * @since 0.4.0
 */
interface HookableInterface {

	/**
	 * Return an array of HookDescriptor objects describing the WordPress
	 * actions and filters this class wants to register.
	 *
	 * @since 0.4.0
	 * @return HookDescriptor[] Array of hook descriptors.
	 */
	public function hooks(): array;
}
