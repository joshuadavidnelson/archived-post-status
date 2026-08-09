<?php

namespace ArchivedPostStatus\Contracts;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * Any class that registers WordPress hooks must implement this interface.
 *
 * Implementors declare their hooks as data rather than registering them
 * imperatively; {@see \ArchivedPostStatus\Hooks\HookLoader} performs the
 * WordPress registration.
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
