<?php
/**
 * `wp post archive-rule` command — prints the resolved cascade and the chain
 * that produced it.
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus\CLI;

use ArchivedPostStatus\AutoArchive\ResolvedRule;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleChain;
use WP_CLI;
use WP_CLI\Utils;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * The debugging tool the auto-archive cascade cannot ship without: prints
 * every cascade level's own contribution for one post, alongside the
 * resolved outcome — which level won, and which level (if any) froze the
 * rest. A read of already-effective rule configuration, not a mutation, so
 * unlike every other command in this namespace it carries no capability
 * gate.
 *
 * Deliberately NOT a {@see Command} subclass: `Command`'s template is built
 * around a batch of post ids each producing one success/warning line, but
 * this command takes exactly one id and renders a table plus a resolved-
 * outcome summary — a fundamentally different output shape.
 *
 * @since 0.5.0
 */
final class ExplainCommand {

	/**
	 * @since 0.5.0
	 * @param RuleChain $chain Resolves the cascade for one post.
	 */
	public function __construct( private readonly RuleChain $chain ) {}

	/**
	 * @since 0.5.0
	 * @param array<int, string|int> $args       Positional args; $args[0] is the post ID.
	 * @param array<string, mixed>   $assoc_args Associative CLI flags (--format).
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical chain-explain accessor.
	 */
	public function explain( array $args, array $assoc_args ): void {
		$post_id = absint( $args[0] ?? 0 );

		$pt_error = $this->post_type_error( $post_id );
		if ( null !== $pt_error ) {
			// WP_CLI is only loaded under a real WP-CLI request; not a composer
			// dependency, same gap the existing phpstan.neon.dist ignoreErrors
			// entries cover for ::success()/::warning()/::add_command().
			// @phpstan-ignore class.notFound
			WP_CLI::error( $pt_error );
			return;
		}

		[ $chain, $resolved ] = $this->chain->explain_for( $post_id );

		$this->print_chain( $chain, $resolved, $assoc_args );
		$this->print_outcome( $post_id, $resolved );
	}

	/**
	 * @since 0.5.0
	 * @param int $post_id The post ID to check.
	 * @return string|null Error message, or null if the post type is supported.
	 */
	private function post_type_error( int $post_id ): ?string {
		$post_type = get_post_type( $post_id );

		if ( ! aps_is_supported_post_type( $post_type ) ) {
			return "Post {$post_id} is not a supported post type.";
		}

		return null;
	}

	/**
	 * @since 0.5.0
	 * @param Rule[]                $chain      Each level's own contribution.
	 * @param ResolvedRule          $resolved   The resolved outcome.
	 * @param array<string, mixed>  $assoc_args Associative CLI flags (--format).
	 * @return void
	 */
	private function print_chain( array $chain, ResolvedRule $resolved, array $assoc_args ): void {
		if ( array() === $chain ) {
			// WP_CLI is only loaded under a real WP-CLI request; see explain()'s ::error() call.
			// @phpstan-ignore class.notFound
			WP_CLI::log( 'No cascade level sets a rule for this post.' );
			return;
		}

		$format = (string) Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$fields = array( 'level', 'label', 'days', 'child_mode', 'won', 'froze' );

		// WP_CLI\Utils is only loaded under a real WP-CLI request, same gap as
		// WP_CLI itself above; wp-cli/wp-cli is not a composer dependency of
		// this plugin.
		// @phpstan-ignore function.notFound
		Utils\format_items( $format, $this->chain_items( $chain, $resolved ), $fields );
	}

	/**
	 * @since 0.5.0
	 * @param Rule[]       $chain    Each level's own contribution.
	 * @param ResolvedRule $resolved The resolved outcome.
	 * @return array<int, array<string, string>>
	 */
	private function chain_items( array $chain, ResolvedRule $resolved ): array {
		return array_map(
			static fn ( Rule $rule ): array => array(
				'level'      => $rule->level,
				'label'      => $rule->label,
				'days'       => null !== $rule->days ? (string) $rule->days : '—',
				'child_mode' => $rule->child_mode->value,
				'won'        => $rule->level === $resolved->origin_level ? 'yes' : '',
				'froze'      => $rule->level === $resolved->frozen_by ? 'yes' : '',
			),
			$chain
		);
	}

	/**
	 * @since 0.5.0
	 * @param int          $post_id  The post ID.
	 * @param ResolvedRule $resolved The resolved outcome.
	 * @return void
	 */
	private function print_outcome( int $post_id, ResolvedRule $resolved ): void {
		if ( ! $resolved->is_scheduled() ) {
			// WP_CLI is only loaded under a real WP-CLI request; see explain()'s ::error() call.
			// @phpstan-ignore class.notFound
			WP_CLI::log( "Post {$post_id} has no auto-archive rule applied{$this->frozen_suffix( $resolved, true )}." );
			return;
		}

		WP_CLI::success(
			"Post {$post_id} resolves to {$resolved->days} day(s), from {$resolved->origin_label} "
			. "({$resolved->origin_level} level){$this->frozen_suffix( $resolved, false )}."
		);
	}

	/**
	 * @since 0.5.0
	 * @param ResolvedRule $resolved      The resolved outcome.
	 * @param bool         $unscheduled   Whether this is for the "not scheduled" message,
	 *                                    which phrases the freeze differently since it set no value.
	 * @return string
	 */
	private function frozen_suffix( ResolvedRule $resolved, bool $unscheduled ): string {
		if ( null === $resolved->frozen_by ) {
			return '';
		}

		return $unscheduled
			? " (frozen by {$resolved->frozen_by}, which sets no value of its own)"
			: ", frozen by {$resolved->frozen_by}";
	}
}
