<?php

use Yoast\PHPUnitPolyfills\TestCases\TestCase as BaseTestCase;

/**
 * We will extend this test case to make WP_Mock set up easier
 */
class TestCase extends BaseTestCase {

	/**
	 * Set up with WP_Mock
	 *
	 * @since  0.8
	 */
	public function set_up() {
		\WP_Mock::setUp();
	}

	/**
	 * Tear down with WP_Mock
	 *
	 * @since  0.8
	 */
	public function tear_down() {
		\WP_Mock::tearDown();
	}

	/**
	 * Create a mock post with default values
	 */
	protected function createMockPost( array $args = [] ) {
		$defaults = [
			'ID' => 123,
			'post_type' => 'post',
			'post_status' => 'publish',
			'comment_status' => 'open',
			'ping_status' => 'open',
			'post_title' => 'Test Post'
		];

		$args = array_merge( $defaults, $args );
		$post = \Mockery::mock( 'WP_Post' );

		foreach ( $args as $key => $value ) {
			$post->$key = $value;
		}

		return $post;
	}

	/**
	 * Create a mock screen object
	 */
	protected function createMockScreen( array $args = [] ) {
		$defaults = [
			'base' => 'edit',
			'post_type' => 'post'
		];

		$args = array_merge( $defaults, $args );
		$screen = \Mockery::mock( 'WP_Screen' );

		foreach ( $args as $key => $value ) {
			$screen->$key = $value;
		}

		return $screen;
	}

	/**
	 * Mock WordPress core functions with common patterns
	 */
	protected function mockWordPressCoreFunctions( array $config = [] ) {
		$defaults = [
			'current_user_can_edit_others_posts' => true,
			'get_post_types' => [
				'post' => 'post',
				'page' => 'page'
			],
			'wp_update_post' => true,
			'get_post' => null, // Will be set per test if needed
			'get_the_ID' => null, // Will be set per test if needed
		];

		$config = array_merge( $defaults, $config );

		if ( isset( $config['current_user_can_edit_others_posts'] ) ) {
			// Mock user capability check for editing others' posts
			\WP_Mock::userFunction( 'current_user_can' )
				->with( 'edit_others_posts' )
				->andReturn( $config['current_user_can_edit_others_posts'] );
		}

		if ( isset( $config['get_post_types'] ) ) {
			// Mock post types retrieval for archive support
			\WP_Mock::userFunction( 'get_post_types' )
				->andReturn( $config['get_post_types'] );
		}

		if ( isset( $config['wp_update_post'] ) ) {
			// Mock post update operations
			\WP_Mock::userFunction( 'wp_update_post' )
				->andReturn( $config['wp_update_post'] );
		}

		if ( isset( $config['get_post'] ) ) {
			// Mock post object retrieval
			\WP_Mock::userFunction( 'get_post' )
				->andReturn( $config['get_post'] );
		}

		if ( isset( $config['get_the_ID'] ) ) {
			\WP_Mock::userFunction( 'get_the_ID' )
				->andReturn( $config['get_the_ID'] );
		}
	}

	/**
	 * Mock common APS plugin filters
	 */
	protected function mockAPSFilters( array $config = [] ) {
		$defaults = [
			'excluded_post_types' => [],
			'excluded_post_types_return' => [],
			'supported_post_types' => [
				'post',
				'page'
			],
			'supported_post_types_return' => [
				'post',
				'page'
			],
			'is_read_only' => true,
			'is_read_only_return' => true,
		];

		$config = array_merge( $defaults, $config );

		// Mock filter for excluded post types
		\WP_Mock::onFilter( 'aps_excluded_post_types' )
			->with( $config['excluded_post_types'] )
			->reply( $config['excluded_post_types_return'] );

		// Mock filter for supported post types
		\WP_Mock::onFilter( 'aps_supported_post_types' )
			->with( $config['supported_post_types'] )
			->reply( $config['supported_post_types_return'] );

		if ( isset( $config['is_read_only'] ) ) {
			\WP_Mock::onFilter( 'aps_is_read_only' )
				->with( $config['is_read_only'] )
				->reply( $config['is_read_only_return'] );
		}
	}

	/**
	 * Mock common APS plugin functions
	 */
	protected function mockAPSFunctions( array $config = [] ) {
		$defaults = [
			'_aps_get_archivable_statuses' => [ 'publish' ],
		];

		$config = array_merge( $defaults, $config );

		if ( isset( $config['_aps_get_archivable_statuses'] ) ) {
			\WP_Mock::userFunction( '_aps_get_archivable_statuses' )
				->andReturn( $config['_aps_get_archivable_statuses'] );
		}
	}

	/**
	 * Setup standard test environment with common mocks
	 */
	protected function setupStandardTestEnvironment( array $wp_config = [], array $filter_config = [], array $plugin_config = [] ) {
		$this->mockWordPressCoreFunctions( $wp_config );
		$this->mockAPSFilters( $filter_config );
		$this->mockAPSFunctions( $plugin_config );
	}

	/**
	 * Mock bulk edit permissions scenario
	 */
	protected function mockBulkEditPermissions( $can_edit = true ) {
		\WP_Mock::userFunction( 'current_user_can' )
			->with( 'edit_others_posts' )
			->andReturn( $can_edit );
	}

	/**
	 * Mock post status retrieval
	 */
	protected function mockPostStatus( $post_id, $status ) {
		\WP_Mock::userFunction( 'get_post_status' )
			->with( $post_id )
			->andReturn( $status );
	}

	/**
	 * Mock post metadata operations with flexible expectations
	 */
	protected function mockPostMetaOperations( $post_id = null, $meta_expectations = 'flexible' ) {
		if ( $meta_expectations === 'strict' && $post_id ) {
			\WP_Mock::userFunction( 'add_post_meta' )
				->with( $post_id, 'aps_pre_archive_status', \WP_Mock\Functions::anyOf() )
				->andReturn( true );

			\WP_Mock::userFunction( 'delete_post_meta' )
				->with( $post_id, 'aps_pre_archive_status' )
				->andReturn( true );

			\WP_Mock::userFunction( 'get_post_meta' )
				->with( $post_id, 'aps_pre_archive_status', true )
				->andReturn( 'publish' );
		} else {
			// Flexible expectations for any meta operations
			\WP_Mock::userFunction( 'add_post_meta' )->andReturn( true );
			\WP_Mock::userFunction( 'delete_post_meta' )->andReturn( true );
			\WP_Mock::userFunction( 'get_post_meta' )->andReturn( 'publish' );
		}
	}

	/**
	 * Mock standard WordPress functions for link generation
	 */
	protected function mockLinkGenerationFunctions( array $config = [] ) {
		$defaults = [
			'post_id' => 123,
			'post_type' => 'post',
			'action' => 'archive',
			'base_url' => 'http://example.com/wp-admin/post.php?post=123&action=edit',
			'final_url' => 'http://example.com/wp-admin/post.php?post=123&action=edit&action=archive&_wpnonce=abc123'
		];

		$config = array_merge( $defaults, $config );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) [ '_edit_link' => 'post.php?post=%d&action=edit' ] );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->andReturn( true );

		\WP_Mock::userFunction( 'aps_current_user_can_archive' )
			->andReturn( true );

		\WP_Mock::userFunction( 'admin_url' )
			->andReturn( $config['base_url'] );

		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturn( $config['base_url'] . '&action=' . $config['action'] );

		\WP_Mock::userFunction( '_aps_nonce_key' )
			->andReturn( $config['action'] . '-' . $config['post_id'] );

		\WP_Mock::userFunction( 'wp_nonce_url' )
			->andReturn( $config['final_url'] );

		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( function( $url ) { return $url; } );
	}

	/**
	 * Mock archived post link functions
	 */
	protected function mockArchivedPostLinkFunctions( array $config = [] ) {
		$defaults = [
			'post_id' => 123,
			'post_status' => 'archive',
			'post_type' => 'post',
			'permalink' => 'http://example.com/post/123',
			'final_url' => 'http://example.com/post/123?preview=true'
		];

		$config = array_merge( $defaults, $config );

		\WP_Mock::userFunction( 'is_post_status_viewable' )
			->andReturn( false );

		\WP_Mock::userFunction( 'get_post_type_object' )
			->andReturn( (object) [ 'public' => true ] );

		\WP_Mock::userFunction( 'aps_is_supported_post_type' )
			->andReturn( true );

		\WP_Mock::userFunction( 'is_post_type_viewable' )
			->andReturn( false );

		\WP_Mock::userFunction( 'get_permalink' )
			->andReturn( $config['permalink'] );

		\WP_Mock::userFunction( 'set_url_scheme' )
			->andReturn( $config['permalink'] );

		\WP_Mock::userFunction( 'add_query_arg' )
			->andReturn( $config['final_url'] );

		\WP_Mock::userFunction( 'esc_url' )
			->andReturnUsing( function( $url ) { return $url; } );
	}

	/**
	 * Mock archive/unarchive process functions
	 */
	protected function mockArchiveProcessFunctions( array $config = [] ) {
		$defaults = [
			'post_id' => 123,
			'current_status' => 'publish',
			'archive_meta_status' => 'publish',
			'comment_status' => 'open',
			'ping_status' => 'open'
		];

		$config = array_merge( $defaults, $config );

		\WP_Mock::userFunction( 'get_post_meta' )
			->andReturn( $config['archive_meta_status'] );

		\WP_Mock::userFunction( 'wp_update_post' )
			->andReturn( $config['post_id'] );

		\WP_Mock::userFunction( 'add_post_meta' )
			->andReturn( true );

		\WP_Mock::userFunction( 'delete_post_meta' )
			->andReturn( true );

		\WP_Mock::userFunction( 'get_post_timestamp' )
			->andReturn( time() );

		\WP_Mock::userFunction( 'get_current_user_id' )
			->andReturn( 1 );
	}
}
