<?php
/**
 * Abstract class for features.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 */

namespace ArchivedPostStatus;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; }

/**
 * Abstract class for features.
 *
 * @since 0.4.0
 */
abstract class Feature {

	/**
	 * The name of the feature.
	 *
	 * @since 0.4.0
	 * @var   string
	 */
	protected $name = '';

	/**
	 * Check if the feature is active.
	 *
	 * @since 0.4.0
	 * @return bool
	 */
	public function is_active() {

		$filter_name = 'aps_' . $this->name;

		/**
		 * Filter to toggle the feature.
		 *
		 * @since 0.4.0
		 * @param bool $active True if the feature is active, false to disable it.
		 * @return bool
		 */
		return (bool) apply_filters( $filter_name, true );
	}

	/**
	 * Get the name of the feature.
	 *
	 * @since 0.4.0
	 * @return string
	 */
	public function get_name() {
		return $this->name;
	}

	/**
	 * Initialize the feature.
	 *
	 * @since 0.4.0
	 */
	public function init() {
		if ( ! $this->is_active() ) {
			return;
		}

		$this->register();
	}

	/**
	 * Register the feature.
	 *
	 * This is where the feature should hook into WordPress.
	 *
	 * @since 0.4.0
	 */
	abstract function register();

}
