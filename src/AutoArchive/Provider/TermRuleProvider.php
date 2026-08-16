<?php

namespace ArchivedPostStatus\AutoArchive\Provider;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleProviderInterface;
use ArchivedPostStatus\AutoArchive\TermMeta;
use ArchivedPostStatus\Settings\Schema;
use WP_Term;

/**
 * The term level of the auto-archive cascade — the only level that can
 * return more than one `Rule` for a single post (plan §4.2), because a post
 * can belong to several terms across several opted-in taxonomies.
 *
 * Deliberately does NOT reduce its own output down to one Rule.
 * {@see \ArchivedPostStatus\AutoArchive\RuleChain} already calls
 * {@see \ArchivedPostStatus\AutoArchive\RuleReducer::reduce()} on whatever a
 * provider returns, and that class already implements §4.2 exactly — the
 * minimum days among terms that set one, the strictest child_mode, the label
 * following whichever term supplied the winning days. Re-implementing any of
 * that here would be a second copy of the tie rules to keep in sync.
 *
 * Reads only the taxonomies opted in via `auto_archive_taxonomies`
 * (default `['category']`) — a post's terms in any other taxonomy never
 * reach this class at all, which is also the mechanism that keeps the
 * per-post read cheap: opting in is deliberate, not "every taxonomy this
 * post happens to have".
 *
 * @since 0.5.0
 */
final class TermRuleProvider implements RuleProviderInterface {

	/**
	 * @since 0.5.0
	 * @return string Always 'term'. The dynamic
	 *                `aps_auto_archive_{$level}_rule` hook in
	 *                {@see \ArchivedPostStatus\AutoArchive\RuleChain} depends
	 *                on this literal value.
	 */
	public function level(): string {
		return 'term';
	}

	/**
	 * One Rule per applicable term that sets something, across every
	 * opted-in taxonomy.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return Rule[] Zero or more Rules — see the class docblock for why
	 *                this is never reduced to one here.
	 */
	public function rules_for( int $post_id ): array {
		$taxonomies = self::taxonomies();

		if ( array() === $taxonomies ) {
			return array();
		}

		$rules = array();

		foreach ( $taxonomies as $taxonomy ) {
			foreach ( self::terms_for( $post_id, $taxonomy ) as $term ) {
				$rule = self::rule_for_term( $term );

				if ( $rule instanceof Rule ) {
					$rules[] = $rule;
				}
			}
		}

		return $rules;
	}

	/**
	 * One term's Rule, or null when the term sets nothing.
	 *
	 * A term "sets nothing" when it has no days value AND its child_mode is
	 * the Open default — indistinguishable from a term nobody has ever
	 * configured. Either half alone (a days value with no child_mode
	 * opinion, or a child_mode with no days value) is still a meaningful
	 * contribution to §4.2's resolution and must produce a Rule.
	 *
	 * @since 0.5.0
	 * @param WP_Term $term The term to read.
	 * @return ?Rule
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical term-meta-hydration accessor.
	 */
	private static function rule_for_term( WP_Term $term ): ?Rule {
		$meta = TermMeta::for_term( $term->term_id );

		if ( null === $meta->days && ChildMode::Open === $meta->child_mode ) {
			return null;
		}

		return new Rule( 'term', $meta->days, $meta->child_mode, self::label_for( $term ) );
	}

	/**
	 * UI provenance for one term's Rule, e.g. "Category: News".
	 *
	 * @since 0.5.0
	 * @param WP_Term $term The term.
	 * @return string
	 */
	private static function label_for( WP_Term $term ): string {
		$taxonomy       = get_taxonomy( $term->taxonomy );
		$taxonomy_label = $taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy;

		return sprintf(
			/* translators: 1: Taxonomy singular name, e.g. "Category". 2: Term name, e.g. "News". */
			__( '%1$s: %2$s', 'archived-post-status' ),
			$taxonomy_label,
			$term->name
		);
	}

	/**
	 * A post's terms in one taxonomy, tolerating whatever a misbehaving
	 * `get_terms`/REST filter or an unregistered taxonomy hands back.
	 *
	 * @since 0.5.0
	 * @param int    $post_id  The post ID.
	 * @param string $taxonomy The taxonomy slug.
	 * @return WP_Term[]
	 */
	private static function terms_for( int $post_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $post_id, $taxonomy );

		return is_array( $terms ) ? $terms : array();
	}

	/**
	 * The configured `auto_archive_taxonomies` opt-in list, read the same
	 * way {@see \ArchivedPostStatus\AutoArchive\Provider\SiteRuleProvider}
	 * reads its own settings — through the setting's `aps_*` filter, not
	 * {@see \ArchivedPostStatus\Settings\Store} directly.
	 *
	 * @since 0.5.0
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private static function taxonomies(): array {
		$taxonomies = apply_filters( 'aps_auto_archive_taxonomies', Schema::default_for( 'auto_archive_taxonomies' ) );

		return is_array( $taxonomies ) ? $taxonomies : array();
	}
}
