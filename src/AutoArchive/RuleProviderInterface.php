<?php

namespace ArchivedPostStatus\AutoArchive;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * One cascade level's source of Rules for a given post.
 *
 * {@see RuleChain} assembles an ordered list of these, general to specific,
 * and asks each for its contribution to one post's chain. The return type is
 * always an array, on purpose: **the obvious mistake is returning a bare
 * `Rule` instead of a one-element array.** Every level except term legitimately
 * returns at most one `Rule` — a post has one site, one network default (if
 * any), and at most one override of its own — but only the term level can
 * genuinely return many, because a post can belong to several terms across
 * several opted-in taxonomies. {@see RuleChain} reduces whatever comes back
 * through {@see RuleReducer::reduce()} regardless of how many elements it
 * holds, so a single-Rule level and a many-Rule level implement this
 * identically.
 *
 * @since 0.5.0
 */
interface RuleProviderInterface {

	/**
	 * Which cascade level this provider represents.
	 *
	 * Feeds the dynamic `aps_auto_archive_{$level}_rule` filter name in
	 * {@see RuleChain}, so it must be a stable, filter-name-safe string.
	 *
	 * @since 0.5.0
	 * @return string One of 'network' | 'site' | 'term' | 'post' for the
	 *                levels this release ships; a third-party provider may
	 *                name any other level.
	 */
	public function level(): string;

	/**
	 * This level's Rule(s) for one post.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID being resolved.
	 * @return Rule[] Zero or more Rules; empty when this level has nothing
	 *                to say about this post. Only the term level legitimately
	 *                returns more than one — see the class docblock.
	 */
	public function rules_for( int $post_id ): array;
}
