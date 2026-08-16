<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * What a cascade level inherits from the levels above it — the argument
 * {@see CascadeField} renders against. Storage-free and level-agnostic by
 * design: the caller resolves the ancestor chain (today, hand-built by
 * {@see SettingsPage}; later phases resolve it from a real
 * {@see \ArchivedPostStatus\AutoArchive\ResolvedRule}) and hands the answer
 * to this value object, which knows nothing about where it came from.
 *
 * `$locked` and `$off` are mutually exclusive by construction — a chain can
 * only be frozen one way at the point it first freezes — but this class
 * does not enforce that itself; it is a plain carrier, not a validator.
 *
 * @since 0.5.0
 */
final class CascadeInheritance {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param ?int    $days            The inherited days value, or null when
	 *                                  no ancestor sets one.
	 * @param ?string $origin_label    The ancestor level that supplied
	 *                                  `$days` (e.g. "Site"); null when
	 *                                  `$days` is null.
	 * @param bool    $locked          True when an ancestor's child_mode is
	 *                                  Locked: this level's own value is
	 *                                  read-only, badged with its origin.
	 * @param bool    $off             True when an ancestor's child_mode is
	 *                                  Off: this level's control is hidden
	 *                                  entirely.
	 * @param ?string $frozen_by_label The ancestor level whose Locked/Off
	 *                                  mode is in effect; null when neither
	 *                                  `$locked` nor `$off` is true. Not
	 *                                  necessarily the same level as
	 *                                  `$origin_label` — a level can freeze
	 *                                  the chain while contributing no value
	 *                                  of its own.
	 */
	public function __construct(
		public readonly ?int $days,
		public readonly ?string $origin_label,
		public readonly bool $locked,
		public readonly bool $off,
		public readonly ?string $frozen_by_label
	) {}

	/**
	 * The top of the chain: nothing inherited, nothing frozen. What a
	 * network-level (or otherwise ancestor-less) screen passes.
	 *
	 * @since 0.5.0
	 * @return self
	 */
	public static function none(): self {
		return new self( null, null, false, false, null );
	}

	/**
	 * Whether an ancestor freezes this level, in either mode.
	 *
	 * @since 0.5.0
	 * @return bool
	 */
	public function frozen(): bool {
		return $this->locked || $this->off;
	}
}
