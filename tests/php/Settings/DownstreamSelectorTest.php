<?php
/**
 * Settings\DownstreamSelector Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Settings\DownstreamSelector
 *
 * A dedicated file for the same reason CascadeInheritanceTest exists — see
 * that file's docblock.
 */

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\Settings\DownstreamSelector;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Settings\DownstreamSelector
 */
class DownstreamSelectorTest extends TestCase {

	/**
	 * @covers ArchivedPostStatus\Settings\DownstreamSelector::__construct
	 */
	public function test_constructor_exposes_all_three_properties_readonly() {
		$downstream = new DownstreamSelector( 'auto_archive_child_mode', 'sites', ChildMode::Locked );

		$this->assertSame( 'auto_archive_child_mode', $downstream->field_name );
		$this->assertSame( 'sites', $downstream->children_label );
		$this->assertSame( ChildMode::Locked, $downstream->value );
	}
}
