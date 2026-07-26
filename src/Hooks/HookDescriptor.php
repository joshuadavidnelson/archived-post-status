<?php

namespace ArchivedPostStatus\Hooks;

/**
 * Describes a single WordPress hook registration.
 *
 * A readonly value object representing either an add_action() or add_filter()
 * call. Named constructors make intent clearer at the call site than passing
 * a type string manually.
 *
 * @since 0.4.0
 */
final class HookDescriptor {

	public const TYPE_ACTION = 'action';
	public const TYPE_FILTER = 'filter';

	/**
	 * @param string                     $type          'action' or 'filter' — use TYPE_ACTION / TYPE_FILTER.
	 * @param string                     $hook          The WordPress hook name.
	 * @param array<int|string, mixed>|string|object $callback The callback to register. Constrained to the
	 *                                                  union of PHP-allowed property types that cover every
	 *                                                  `callable` shape (string function name, [$obj, 'method'],
	 *                                                  ['Class', 'method'], `Closure`, or any invokable object).
	 *                                                  Factories declare `callable` at the parameter boundary
	 *                                                  for the compile-time check; the property declares the
	 *                                                  union directly because PHP disallows `callable` on
	 *                                                  properties.
	 * @param int                        $priority      Hook priority. Default 10.
	 * @param int                        $accepted_args Number of arguments the callback accepts. Default 1.
	 */
	private function __construct(
		public readonly string                $type,
		public readonly string                $hook,
		public readonly array|string|object   $callback,
		public readonly int                   $priority      = 10,
		public readonly int                   $accepted_args = 1,
	) {}

	/**
	 * Create a descriptor for an action hook.
	 *
	 * @since 0.4.0
	 * @param string   $hook          The WordPress action name.
	 * @param callable $callback      The callback to register.
	 * @param int      $priority      Hook priority. Default 10.
	 * @param int      $accepted_args Number of arguments. Default 1.
	 * @return self
	 */
	public static function action(
		string   $hook,
		callable $callback,
		int      $priority      = 10,
		int      $accepted_args = 1
	): self {
		return new self( self::TYPE_ACTION, $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * Create a descriptor for a filter hook.
	 *
	 * @since 0.4.0
	 * @param string   $hook          The WordPress filter name.
	 * @param callable $callback      The callback to register.
	 * @param int      $priority      Hook priority. Default 10.
	 * @param int      $accepted_args Number of arguments. Default 1.
	 * @return self
	 */
	public static function filter(
		string   $hook,
		callable $callback,
		int      $priority      = 10,
		int      $accepted_args = 1
	): self {
		return new self( self::TYPE_FILTER, $hook, $callback, $priority, $accepted_args );
	}

	/**
	 * Whether this descriptor represents an action hook.
	 *
	 * @since 0.4.0
	 */
	public function is_action(): bool {
		return self::TYPE_ACTION === $this->type;
	}
}
