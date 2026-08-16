<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

/**
 * Markup and escaping for every settings field that is NOT part of the
 * auto-archive cascade — {@see CascadeField} owns `auto_archive_days` and
 * `auto_archive_child_mode`, this class owns everything else Schema
 * describes: checkbox, number, an enum select, and a multi-select checkbox
 * list (post types / taxonomies).
 *
 * Same escaped-HTML contract as {@see CascadeField} and
 * {@see \ArchivedPostStatus\Admin\ArchiveColumnCellRenderer}: every method
 * returns HTML that is already escaped and ready to echo as-is. Callers must
 * not run this output through esc_html()/wp_kses() again.
 *
 * @since 0.5.0
 */
final class SettingsRenderer {

	/**
	 * A checkbox field, e.g. `is_read_only` / `scheduled_archive_enabled`.
	 *
	 * @since 0.5.0
	 * @param string $field_name
	 * @param string $label
	 * @param string $description
	 * @param bool   $value
	 * @return string Escaped HTML, ready to echo as-is.
	 */
	public static function checkbox( string $field_name, string $label, string $description, bool $value ): string {
		return sprintf(
			'<fieldset><label for="%1$s"><input type="checkbox" id="%1$s" name="%1$s" value="1"%2$s /> %3$s</label>'
			. '<p class="description">%4$s</p></fieldset>',
			esc_attr( $field_name ),
			$value ? ' checked="checked"' : '',
			esc_html( $label ),
			esc_html( $description )
		);
	}

	/**
	 * A plain non-negative integer field, e.g. `auto_archive_grace_days`.
	 *
	 * @since 0.5.0
	 * @param string $field_name
	 * @param string $label
	 * @param string $description
	 * @param int    $value
	 * @return string Escaped HTML, ready to echo as-is.
	 */
	public static function number( string $field_name, string $label, string $description, int $value ): string {
		return sprintf(
			'<fieldset><label for="%1$s">%2$s</label> '
			. '<input type="number" min="0" step="1" id="%1$s" name="%1$s" value="%3$d" class="small-text" />'
			. '<p class="description">%4$s</p></fieldset>',
			esc_attr( $field_name ),
			esc_html( $label ),
			$value,
			esc_html( $description )
		);
	}

	/**
	 * A single-choice enum field, e.g. `auto_archive_age_basis`.
	 *
	 * @since 0.5.0
	 * @param string                $field_name
	 * @param string                $label
	 * @param string                $description
	 * @param array<string, string> $choices Value => display label.
	 * @param string                $value   The currently-selected value.
	 * @return string Escaped HTML, ready to echo as-is.
	 */
	public static function select( string $field_name, string $label, string $description, array $choices, string $value ): string {
		$options = '';
		foreach ( $choices as $choice_value => $choice_label ) {
			$options .= self::select_option( (string) $choice_value, $choice_label, $value );
		}

		return sprintf(
			'<fieldset><label for="%1$s">%2$s</label> <select id="%1$s" name="%1$s">%3$s</select>'
			. '<p class="description">%4$s</p></fieldset>',
			esc_attr( $field_name ),
			esc_html( $label ),
			$options,
			esc_html( $description )
		);
	}

	/**
	 * A checkbox-list multi-select field, e.g. `auto_archive_types` against
	 * `aps_get_supported_post_types()`, or `auto_archive_taxonomies` against
	 * `get_taxonomies()`.
	 *
	 * @since 0.5.0
	 * @param string                $field_name
	 * @param string                $label
	 * @param string                $description
	 * @param array<string, string> $choices Slug => display label.
	 * @param string[]              $values  Currently-selected slugs.
	 * @return string Escaped HTML, ready to echo as-is.
	 */
	public static function multi_checkbox( string $field_name, string $label, string $description, array $choices, array $values ): string {
		$options = '';
		foreach ( $choices as $choice_value => $choice_label ) {
			$options .= self::multi_checkbox_option( $field_name, (string) $choice_value, $choice_label, $values );
		}

		return sprintf(
			'<fieldset><legend>%1$s</legend>%2$s<p class="description">%3$s</p></fieldset>',
			esc_html( $label ),
			$options,
			esc_html( $description )
		);
	}

	/**
	 * @since 0.5.0
	 * @param string $value
	 * @param string $label
	 * @param string $current
	 * @return string Escaped HTML.
	 */
	private static function select_option( string $value, string $label, string $current ): string {
		return sprintf(
			'<option value="%1$s"%2$s>%3$s</option>',
			esc_attr( $value ),
			$value === $current ? ' selected="selected"' : '',
			esc_html( $label )
		);
	}

	/**
	 * @since 0.5.0
	 * @param string   $field_name
	 * @param string   $value
	 * @param string   $label
	 * @param string[] $current
	 * @return string Escaped HTML.
	 */
	private static function multi_checkbox_option( string $field_name, string $value, string $label, array $current ): string {
		$option_id = $field_name . '_' . $value;

		return sprintf(
			'<p><label for="%1$s"><input type="checkbox" id="%1$s" name="%2$s[]" value="%3$s"%4$s /> %5$s</label></p>',
			esc_attr( $option_id ),
			esc_attr( $field_name ),
			esc_attr( $value ),
			in_array( $value, $current, true ) ? ' checked="checked"' : '',
			esc_html( $label )
		);
	}

	/** Prevent instantiation — this is a static facade. */
	private function __construct() {}
}
