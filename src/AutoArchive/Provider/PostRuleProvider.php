<?php

namespace ArchivedPostStatus\AutoArchive\Provider;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleProviderInterface;

/**
 * The post level of the auto-archive cascade — the most specific level, and
 * the last one consulted (plan §4.6). A post's own override lives on
 * postmeta under {@see self::META_DAYS}, the SAME literal key
 * {@see \ArchivedPostStatus\AutoArchive\TermMeta::META_DAYS} uses for the
 * term level — deliberate, since it is the same concept ("this level's own
 * days value") at a different storage granularity, not a coincidence to
 * disambiguate.
 *
 * The post level has no children, so every `Rule` this class returns
 * carries {@see ChildMode::Open}. This is a convention of the level, not a
 * value anyone stores: nothing ever writes a post-level `child_mode`, and
 * {@see \ArchivedPostStatus\AutoArchive\RuleResolver} treats every level
 * identically regardless — giving the post level a fifth, `child_mode`-less
 * `Rule` shape would be exactly the kind of special case the resolver is
 * written to avoid, per {@see \ArchivedPostStatus\AutoArchive\Rule}'s own
 * class docblock.
 *
 * @since 0.5.0
 */
final class PostRuleProvider implements RuleProviderInterface {

	/**
	 * The post meta key for a post's own days override.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	public const META_DAYS = '_aps_auto_archive_days';

	/**
	 * @since 0.5.0
	 * @return string Always 'post'. The dynamic
	 *                `aps_auto_archive_{$level}_rule` hook in
	 *                {@see \ArchivedPostStatus\AutoArchive\RuleChain} depends
	 *                on this literal value.
	 */
	public function level(): string {
		return 'post';
	}

	/**
	 * The post's own override, or none.
	 *
	 * An empty-string return from `get_post_meta( …, true )` (WordPress's
	 * "no such row" signal) is "this post sets nothing", not a stored `0` —
	 * the same null-vs-zero hazard every other level's own meta reader
	 * already guards against.
	 *
	 * @since 0.5.0
	 * @param int $post_id The post ID.
	 * @return Rule[] Zero or one Rule.
	 */
	public function rules_for( int $post_id ): array {
		$raw_days = get_post_meta( $post_id, self::META_DAYS, true );

		if ( '' === $raw_days ) {
			return array();
		}

		return array(
			new Rule( $this->level(), (int) $raw_days, ChildMode::Open, __( 'Post override', 'archived-post-status' ) ),
		);
	}
}
