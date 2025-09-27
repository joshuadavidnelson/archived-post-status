<?php
/**
 * Feature Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Feature
 */

/**
 * Feature test case
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Feature
 */
class FeatureTest extends TestCase {

	/**
	 * Test feature for testing abstract methods
	 * @var ArchivedPostStatus\Feature
	 */
	protected $feature;

	/**
	 * Set up the test.
	 *
	 * @since 0.4.0
	 */
	public function set_up() {
		parent::set_up();
		$this->feature = $this->createTestFeature( 'test_feature' );
	}

	/**
	 * Test that features are active by default
	 *
	 * @covers ArchivedPostStatus\Feature::is_active
	 */
	public function test_feature_active_by_default() {
		// Mock the filter to return default value
		\WP_Mock::onFilter( 'aps_test_feature' )
			->with( true )
			->reply( true );

		$this->assertTrue( $this->feature->is_active() );
	}

	/**
	 * Test that features can be disabled via filter
	 *
	 * @covers ArchivedPostStatus\Feature::is_active
	 */
	public function test_feature_can_be_disabled() {
		// Filter returns false
		\WP_Mock::onFilter( 'aps_test_feature' )
			->with( true )
			->reply( false );

		$this->assertFalse( $this->feature->is_active() );
	}

	/**
	 * Test that get_name returns the correct feature name
	 *
	 * @covers ArchivedPostStatus\Feature::get_name
	 */
	public function test_get_name_returns_feature_name() {
		$this->assertEquals( 'test_feature', $this->feature->get_name() );
	}

	/**
	 * Test that init calls register when feature is active
	 *
	 * @covers ArchivedPostStatus\Feature::init
	 */
	public function test_init_calls_register_when_active() {
		// Mock the filter to return true (active)
		\WP_Mock::onFilter( 'aps_test_feature' )
			->with( true )
			->reply( true );

		$this->feature->init();

		$this->assertTrue( $this->feature->wasRegisterCalled() );
	}

	/**
	 * Test that init skips register when feature is inactive
	 *
	 * @covers ArchivedPostStatus\Feature::init
	 */
	public function test_init_skips_register_when_inactive() {
		// Mock the filter to return false (inactive)
		\WP_Mock::onFilter( 'aps_test_feature' )
			->with( true )
			->reply( false );

		$this->feature->init();

		$this->assertFalse( $this->feature->wasRegisterCalled() );
	}

	/**
	 * Helper to create a concrete feature for testing abstract methods
	 */
	protected function createTestFeature( $name = 'test_feature' ) {
		return new class( $name ) extends ArchivedPostStatus\Feature {
			private $test_name;
			private $register_called = false;

			public function __construct( $name ) {
				$this->test_name = $name;
				$this->name = $name;
			}

			public function register() {
				$this->register_called = true;
				// Mock register implementation
			}

			public function wasRegisterCalled() {
				return $this->register_called;
			}
		};
	}
}
