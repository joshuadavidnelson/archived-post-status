<?php

namespace ArchivedPostStatus\AutoArchive\Provider;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\Rule;
use ArchivedPostStatus\AutoArchive\RuleProviderInterface;
use ArchivedPostStatus\Settings\NetworkActivation;
use ArchivedPostStatus\Settings\NetworkStore;

/**
 * The network level of the auto-archive cascade — the top of the chain
 * (plan §4.6). It inherits nothing and, on a network-activated install,
 * always has the site level below it.
 *
 * Empty on anything other than a network-activated multisite install: a
 * single site, an inactive-at-the-network-level site on a multisite
 * install, and — checked first, before either — a non-multisite install
 * altogether. See {@see NetworkActivation} for why this never calls
 * `is_plugin_active_for_network()`, the trap that would fatal on a
 * front-end, cron, or REST request.
 *
 * Unlike {@see SiteRuleProvider}, this reads {@see NetworkStore} directly
 * rather than through an `aps_*` filter. The network option has no
 * `HookAdapter`-style filter layer of its own; reusing the site-level
 * filter names (`aps_auto_archive_days`, etc.) here would collide with
 * {@see \ArchivedPostStatus\Settings\HookAdapter}'s own binding of those
 * exact names to the SITE store.
 *
 * @since 0.5.0
 */
final class NetworkRuleProvider implements RuleProviderInterface {

	/**
	 * @since 0.5.0
	 * @return string Always 'network'. The dynamic
	 *                `aps_auto_archive_{$level}_rule` hook in
	 *                {@see \ArchivedPostStatus\AutoArchive\RuleChain} depends
	 *                on this literal value.
	 */
	public function level(): string {
		return 'network';
	}

	/**
	 * The network's single rule for one post, or none.
	 *
	 * Independent of `$post_id`: the network level has no post-type opt-in
	 * of its own — {@see \ArchivedPostStatus\Settings\Schema}'s
	 * `auto_archive_types` key is site-level only — so every post on every
	 * network-activated site is offered the same network rule.
	 *
	 * @since 0.5.0
	 * @param int $post_id Unused — see above.
	 * @return Rule[] Zero or one Rule.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical network-activation/network-store/enum accessors.
	 */
	public function rules_for( int $post_id ): array {
		unset( $post_id );

		if ( ! NetworkActivation::active() ) {
			return array();
		}

		if ( ! (bool) NetworkStore::get( 'auto_archive_enabled' ) ) {
			return array();
		}

		$days = NetworkStore::get( 'auto_archive_days' );
		$days = ( null === $days ) ? null : (int) $days;

		$child_mode = ChildMode::tryFrom( (string) NetworkStore::get( 'auto_archive_child_mode' ) ) ?? ChildMode::Open;

		return array(
			new Rule( $this->level(), $days, $child_mode, __( 'Network default', 'archived-post-status' ) ),
		);
	}
}
