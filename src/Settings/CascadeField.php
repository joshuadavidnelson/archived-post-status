<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;

/**
 * The one inheritance-aware field renderer for the auto-archive cascade
 * (plan §5.9), shared by the site screen (this phase), the network screen,
 * the term form, and the post editor (phases 7/8/9).
 *
 * Level-agnostic and storage-free by design: it renders exactly what it is
 * told via {@see CascadeInheritance} and {@see DownstreamSelector} and never
 * reads an option, term meta, post meta, or the cascade itself. Every method
 * here returns already-escaped HTML, ready to echo as-is — the same
 * no-double-escape contract {@see \ArchivedPostStatus\Admin\ArchiveColumnCellRenderer}
 * establishes; callers must not run this output through esc_html()/wp_kses()
 * again.
 *
 * Always renders the same three things, in order:
 *   1. What is inherited, and from where — always visible.
 *   2. This level's own control — editable, or a read-only badge when an
 *      ancestor is Locked, or nothing when an ancestor is Off.
 *   3. The downstream Open/Locked/Off selector, only when the caller passes
 *      one — i.e. only for a level that has children.
 *
 * @since 0.5.0
 */
final class CascadeField {

	/**
	 * Render one cascade field: the always-visible inheritance line, this
	 * level's own control (editable, read-only, or absent per the ancestor
	 * freeze state), and — when `$downstream` is given — the Open/Locked/Off
	 * selector for the level below.
	 *
	 * @since 0.5.0
	 * @param string             $field_name  `name`/`id` for the editable
	 *                                         days input.
	 * @param string             $label       The field's own label.
	 * @param string             $description Short helper text under the label.
	 * @param CascadeInheritance $inheritance What this level inherits.
	 * @param ?int               $own_days    This level's own stored days
	 *                                         value (null = not set); ignored
	 *                                         when `$inheritance->frozen()`.
	 * @param ?DownstreamSelector $downstream  The downstream control, or null
	 *                                         for a level with no children.
	 * @return string Escaped HTML, ready to echo as-is.
	 */
	public static function render(
		string $field_name,
		string $label,
		string $description,
		CascadeInheritance $inheritance,
		?int $own_days,
		?DownstreamSelector $downstream = null
	): string {
		$html  = '<fieldset class="aps-cascade-field">';
		$html .= self::render_legend( $label, $description );
		$html .= self::render_inherited_line( $inheritance );
		$html .= self::render_level_control( $field_name, $inheritance, $own_days, $downstream );
		$html .= '</fieldset>';

		return $html;
	}

	/**
	 * This level's own control section: read-only/absent while frozen,
	 * otherwise the editable input plus (when given) the downstream
	 * selector.
	 *
	 * @since 0.5.0
	 * @param string              $field_name
	 * @param CascadeInheritance  $inheritance
	 * @param ?int                $own_days
	 * @param ?DownstreamSelector $downstream
	 * @return string Escaped HTML.
	 */
	private static function render_level_control(
		string $field_name,
		CascadeInheritance $inheritance,
		?int $own_days,
		?DownstreamSelector $downstream
	): string {
		if ( $inheritance->off ) {
			return self::render_off_notice( $inheritance );
		}

		if ( $inheritance->locked ) {
			return self::render_locked_badge( $inheritance );
		}

		return self::render_editable_control( $field_name, $own_days )
			. self::render_downstream_selector( $downstream );
	}

	/**
	 * @since 0.5.0
	 * @param string $label
	 * @param string $description
	 * @return string Escaped HTML.
	 */
	private static function render_legend( string $label, string $description ): string {
		return '<legend>' . esc_html( $label ) . '</legend>'
			. '<p class="description">' . esc_html( $description ) . '</p>';
	}

	/**
	 * The always-visible "what is inherited, and from where" line.
	 *
	 * @since 0.5.0
	 * @param CascadeInheritance $inheritance
	 * @return string Escaped HTML.
	 */
	private static function render_inherited_line( CascadeInheritance $inheritance ): string {
		if ( null === $inheritance->days ) {
			return '<p class="aps-cascade-inherited">'
				. esc_html__( 'No value is inherited from a higher level.', 'archived-post-status' )
				. '</p>';
		}

		return '<p class="aps-cascade-inherited">' . sprintf(
			/* translators: 1: the ancestor level that supplied the value, e.g. "Site". 2: the inherited number of days. */
			esc_html__( 'Inherited from %1$s: %2$d days', 'archived-post-status' ),
			esc_html( (string) $inheritance->origin_label ),
			(int) $inheritance->days
		) . '</p>';
	}

	/**
	 * Read-only badge shown when an ancestor is Locked — e.g.
	 * "365 days — locked by Network".
	 *
	 * @since 0.5.0
	 * @param CascadeInheritance $inheritance
	 * @return string Escaped HTML.
	 */
	private static function render_locked_badge( CascadeInheritance $inheritance ): string {
		return '<p class="aps-cascade-locked-value"><strong>' . sprintf(
			/* translators: 1: the locked number of days. 2: the ancestor level that locked it, e.g. "Network". */
			esc_html__( '%1$d days — locked by %2$s', 'archived-post-status' ),
			(int) $inheritance->days,
			esc_html( (string) $inheritance->frozen_by_label )
		) . '</strong></p>';
	}

	/**
	 * Shown in place of any control when an ancestor is Off.
	 *
	 * @since 0.5.0
	 * @param CascadeInheritance $inheritance
	 * @return string Escaped HTML.
	 */
	private static function render_off_notice( CascadeInheritance $inheritance ): string {
		return '<p class="aps-cascade-off">' . sprintf(
			/* translators: %s: the ancestor level that hid this setting, e.g. "Network". */
			esc_html__( 'Hidden by %s.', 'archived-post-status' ),
			esc_html( (string) $inheritance->frozen_by_label )
		) . '</p>';
	}

	/**
	 * The editable days input, shown only while this level is not frozen.
	 * An empty value means "inherit" — there is no separate enabled/disabled
	 * checkbox, matching {@see \ArchivedPostStatus\Schedule\ScheduleMeta}'s
	 * own "a positive value is enabled" convention.
	 *
	 * @since 0.5.0
	 * @param string $field_name
	 * @param ?int   $own_days
	 * @return string Escaped HTML.
	 */
	private static function render_editable_control( string $field_name, ?int $own_days ): string {
		return sprintf(
			'<p><label for="%1$s">%2$s</label> '
			. '<input type="number" min="1" step="1" id="%1$s" name="%1$s" value="%3$s" class="small-text" /> '
			. '<span class="description">%4$s</span></p>',
			esc_attr( $field_name ),
			esc_html__( 'Override for this level:', 'archived-post-status' ),
			esc_attr( null === $own_days ? '' : (string) $own_days ),
			esc_html__( 'days (leave blank to inherit)', 'archived-post-status' )
		);
	}

	/**
	 * The Open/Locked/Off downstream selector, or an empty string when the
	 * caller passed none — i.e. this level has no children.
	 *
	 * @since 0.5.0
	 * @param ?DownstreamSelector $downstream
	 * @return string Escaped HTML.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- ChildMode's own cases() is the canonical way to enumerate a backed enum.
	 */
	private static function render_downstream_selector( ?DownstreamSelector $downstream ): string {
		if ( null === $downstream ) {
			return '';
		}

		$options = '';
		foreach ( ChildMode::cases() as $mode ) {
			$options .= self::render_downstream_option( $downstream, $mode );
		}

		return '<fieldset class="aps-cascade-downstream">'
			. '<legend>' . esc_html__( 'For the level below', 'archived-post-status' ) . '</legend>'
			. $options
			. '</fieldset>';
	}

	/**
	 * One Open/Locked/Off radio option, phrased as an outcome (§5.9) rather
	 * than the enum's own jargon.
	 *
	 * @since 0.5.0
	 * @param DownstreamSelector $downstream
	 * @param ChildMode          $mode
	 * @return string Escaped HTML.
	 */
	private static function render_downstream_option( DownstreamSelector $downstream, ChildMode $mode ): string {
		$option_id = $downstream->field_name . '_' . $mode->value;

		return sprintf(
			'<p><label for="%1$s"><input type="radio" id="%1$s" name="%2$s" value="%3$s"%4$s /> %5$s</label></p>',
			esc_attr( $option_id ),
			esc_attr( $downstream->field_name ),
			esc_attr( $mode->value ),
			$mode === $downstream->value ? ' checked="checked"' : '',
			esc_html( self::outcome_label( $mode, $downstream->children_label ) )
		);
	}

	/**
	 * The outcome-phrased label for one downstream mode. Assembled rather
	 * than a flat per-mode string table so `$children_label` (which varies
	 * per host — "sites", "categories", "posts") can be substituted in.
	 *
	 * @since 0.5.0
	 * @param ChildMode $mode
	 * @param string    $children_label Lowercase plural noun, e.g. "sites".
	 * @return string Unescaped; the caller escapes.
	 */
	private static function outcome_label( ChildMode $mode, string $children_label ): string {
		return match ( $mode ) {
			ChildMode::Open   => sprintf(
				/* translators: %s: capitalized plural label for the level below this one, e.g. "Sites". */
				__( '%s may set their own', 'archived-post-status' ),
				self::capitalize_first( $children_label )
			),
			ChildMode::Locked => sprintf(
				/* translators: %s: capitalized plural label for the level below this one, e.g. "Sites". */
				__( '%s see this value, read-only', 'archived-post-status' ),
				self::capitalize_first( $children_label )
			),
			ChildMode::Off    => sprintf(
				/* translators: %s: plural label for the level below this one, e.g. "sites". */
				__( 'Hide this from %s', 'archived-post-status' ),
				$children_label
			),
		};
	}

	/**
	 * Uppercases only the leading character, multibyte-safe — `ucfirst()` is
	 * byte-based and silently leaves a non-ASCII leading character (Cyrillic,
	 * Greek, an accented Latin letter, any of which a translated
	 * `$children_label` can start with) lowercase instead of capitalizing it.
	 *
	 * @since 0.5.0
	 * @param string $text
	 * @return string
	 */
	private static function capitalize_first( string $text ): string {
		if ( '' === $text || ! function_exists( 'mb_substr' ) || ! function_exists( 'mb_strtoupper' ) ) {
			return ucfirst( $text );
		}

		return mb_strtoupper( mb_substr( $text, 0, 1 ) ) . mb_substr( $text, 1 );
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
