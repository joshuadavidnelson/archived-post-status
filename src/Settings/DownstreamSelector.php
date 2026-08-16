<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;

/**
 * The Open/Locked/Off control {@see CascadeField} renders for a level that
 * has children — network and site (both have a level below them) and term
 * (has post below it). Never constructed for the post level, which has no
 * children to freeze.
 *
 * @since 0.5.0
 */
final class DownstreamSelector {

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param string    $field_name     The `name` attribute the three radio
	 *                                   inputs share.
	 * @param string    $children_label Lowercase plural noun for the level
	 *                                   below this one, e.g. "sites",
	 *                                   "categories", "posts" — used to build
	 *                                   the outcome-phrased option labels
	 *                                   (§5.9): "Sites may set their own" /
	 *                                   "Sites see this value, read-only" /
	 *                                   "Hide this from sites".
	 * @param ChildMode $value          This level's own currently-stored
	 *                                   child_mode.
	 */
	public function __construct(
		public readonly string $field_name,
		public readonly string $children_label,
		public readonly ChildMode $value
	) {}
}
