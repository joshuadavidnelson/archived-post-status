<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Collapses many Rules at one cascade level into the single Rule {@see
 * RuleResolver} consumes for that level.
 *
 * Only the term level can produce more than one Rule for a given post — a
 * post can belong to several terms across several opted-in taxonomies — but
 * this class stays level-agnostic per the plan's §4.2.
 *
 * Value and freeze resolve INDEPENDENTLY, and that has a consequence worth
 * stating plainly because it will otherwise surprise someone: a post in
 * News (6 days, Locked) and Features (3 days, Open) reduces to 3 days,
 * frozen — stricter than either term states alone. The days minimum is
 * taken across every rule that has one, the child_mode maximum (strictest)
 * is taken across every rule regardless of whether it set days, and neither
 * calculation is aware of the other. This is deliberate, not an oversight;
 * the alternative — "minimum among locked terms only" — is more code for a
 * case that is rare in practice.
 *
 * @since 0.5.0
 */
final class RuleReducer {

	/**
	 * Reduce many Rules at one level to one.
	 *
	 * @since 0.5.0
	 * @param Rule[] $rules Rules to collapse, any order.
	 * @param string $level The cascade level the reduced Rule represents.
	 * @return ?Rule The reduced Rule, or null for an empty input array.
	 */
	public static function reduce( array $rules, string $level ): ?Rule {
		if ( array() === $rules ) {
			return null;
		}

		$days  = null;
		$label = null;

		foreach ( $rules as $rule ) {
			if ( null !== $rule->days && ( null === $days || $rule->days < $days ) ) {
				$days  = $rule->days;
				$label = $rule->label;
			}
		}

		return new Rule(
			$level,
			$days,
			self::strictest_child_mode( $rules ),
			$label ?? $rules[0]->label
		);
	}

	/**
	 * The strictest child_mode present across a rule set: Off > Locked > Open.
	 *
	 * @since 0.5.0
	 * @param Rule[] $rules Rules to inspect.
	 * @return ChildMode
	 */
	private static function strictest_child_mode( array $rules ): ChildMode {
		$modes = array_map( static fn ( Rule $rule ): ChildMode => $rule->child_mode, $rules );

		if ( in_array( ChildMode::Off, $modes, true ) ) {
			return ChildMode::Off;
		}

		if ( in_array( ChildMode::Locked, $modes, true ) ) {
			return ChildMode::Locked;
		}

		return ChildMode::Open;
	}
}
