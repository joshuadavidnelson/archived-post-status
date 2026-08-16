<?php
/**
 * Settings\SettingsRenderer Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\SettingsRenderer
 */

use ArchivedPostStatus\Settings\SettingsRenderer;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\SettingsRenderer
 */
class SettingsRendererTest extends TestCase {

	/**
	 * @covers ArchivedPostStatus\Settings\SettingsRenderer::checkbox
	 */
	public function test_checkbox_marks_checked_when_true() {
		$html = SettingsRenderer::checkbox( 'is_read_only', 'Read only', 'help', true );

		$this->assertStringContainsString( 'name="is_read_only"', $html );
		$this->assertStringContainsString( 'checked="checked"', $html );
	}

	/**
	 * @covers ArchivedPostStatus\Settings\SettingsRenderer::checkbox
	 */
	public function test_checkbox_omits_checked_when_false() {
		$html = SettingsRenderer::checkbox( 'is_read_only', 'Read only', 'help', false );

		$this->assertStringNotContainsString( 'checked="checked"', $html );
	}

	/**
	 * @covers ArchivedPostStatus\Settings\SettingsRenderer::number
	 */
	public function test_number_renders_the_current_value() {
		$html = SettingsRenderer::number( 'auto_archive_grace_days', 'Grace', 'help', 7 );

		$this->assertStringContainsString( 'name="auto_archive_grace_days"', $html );
		$this->assertStringContainsString( 'value="7"', $html );
	}

	/**
	 * @covers ArchivedPostStatus\Settings\SettingsRenderer::select
	 */
	public function test_select_marks_only_the_current_choice_selected() {
		$html = SettingsRenderer::select(
			'auto_archive_age_basis',
			'Basis',
			'help',
			array(
				'modified'  => 'Last modified',
				'published' => 'Published',
			),
			'published'
		);

		$this->assertMatchesRegularExpression( '/value="published" selected="selected"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="modified" selected="selected"/', $html );
	}

	/**
	 * @covers ArchivedPostStatus\Settings\SettingsRenderer::multi_checkbox
	 */
	public function test_multi_checkbox_marks_only_selected_values_checked() {
		$html = SettingsRenderer::multi_checkbox(
			'auto_archive_types',
			'Post types',
			'help',
			array(
				'post' => 'Post',
				'page' => 'Page',
			),
			array( 'post' )
		);

		$this->assertMatchesRegularExpression( '/value="post" checked="checked"/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="page" checked="checked"/', $html );
		$this->assertStringContainsString( 'name="auto_archive_types[]"', $html );
	}

	/**
	 * Every renderer routes labels through esc_html() — a hostile choice
	 * label must not reach the page unescaped.
	 *
	 * @covers ArchivedPostStatus\Settings\SettingsRenderer::select
	 */
	public function test_select_escapes_choice_labels() {
		\WP_Mock::userFunction( 'esc_html' )
			->andReturnUsing( static fn ( $text ) => htmlspecialchars( (string) $text, ENT_QUOTES ) );

		$html = SettingsRenderer::select(
			'field',
			'label',
			'help',
			array( 'x' => '<script>alert(1)</script>' ),
			'x'
		);

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}
}
