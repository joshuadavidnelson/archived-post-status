<?php

namespace ArchivedPostStatus;

/**
 * Simple PSR-4 compatible class loader.
 *
 * Provides autoloading without requiring Composer's autoloader to be
 * shipped with the plugin. Composer is used for development tooling
 * only (PHPUnit, PHPStan, PHPCS) and is never a runtime dependency.
 *
 * @since 0.4.0
 */
final class Loader {

	/**
	 * Namespace prefix to base directory mappings.
	 *
	 * @since 0.4.0
	 * @var array<string, string>
	 */
	private array $namespaces = array();

	/**
	 * Whether {@see register()} has already pushed this loader onto the SPL
	 * autoload stack. Guards against double-registration if a caller invokes
	 * `register()` twice (which would otherwise stack two copies of the
	 * same `load_class` callable, wasting cycles on every autoload event).
	 */
	private bool $registered = false;

	/** Prevent direct instantiation — use init(). */
	private function __construct() {}

	/**
	 * Register a namespace prefix with a base directory.
	 *
	 * Private because the only legitimate caller is {@see init()} — the
	 * loader is configured once at plugin bootstrap with a fixed mapping
	 * (`ArchivedPostStatus` → `src/`). External code has no reason to
	 * extend the namespace table at runtime.
	 *
	 * Tests that need a test-only namespace mapping reach in via reflection;
	 * see {@see LoaderTest::add_namespace_via_reflection()}.
	 *
	 * @since 0.4.0
	 * @param string $prefix   The namespace prefix.
	 * @param string $base_dir The base directory for the namespace prefix.
	 *
	 * @SuppressWarnings("PHPMD.UnusedPrivateMethod") -- called by {@see init()};
	 * PHPMD's reachability analysis can't see the static `$loader->add_namespace()`
	 * call site through `self`-typed instance dispatch on this readonly-array
	 * pattern.
	 */
	private function add_namespace( string $prefix, string $base_dir ): void {
		$prefix   = trim( $prefix, '\\' ) . '\\';
		$base_dir = rtrim( $base_dir, '/' ) . '/';

		$this->namespaces[ $prefix ] = $base_dir;
	}

	/**
	 * Register this loader with the SPL autoloader stack.
	 *
	 * Idempotent: a second call is a no-op so that accidental
	 * double-bootstrapping doesn't stack two identical callbacks on the
	 * SPL stack (each autoload event would then run `load_class` twice).
	 *
	 * @since 0.4.0
	 */
	public function register(): void {
		if ( $this->registered ) {
			return;
		}

		spl_autoload_register( array( $this, 'load_class' ) );
		$this->registered = true;
	}

	/**
	 * Load the file for a given fully-qualified class name.
	 *
	 * @since 0.4.0
	 * @param string $class The fully-qualified class name.
	 */
	public function load_class( string $class ): void {
		foreach ( $this->namespaces as $prefix => $base_dir ) {
			$len = strlen( $prefix );

			if ( strncmp( $class, $prefix, $len ) !== 0 ) {
				continue;
			}

			$file = $base_dir . str_replace( '\\', '/', substr( $class, $len ) ) . '.php';

			if ( file_exists( $file ) ) {
				require $file;
				return;
			}
		}
	}

	/**
	 * Create, configure, and register the loader for this plugin.
	 *
	 * Loads the plugin's src/ namespace and the public `aps_*` API split
	 * across `src/functions/functions.php` (active facades) and
	 * `src/functions/deprecated.php` (deprecated facades + filter shims).
	 * Called once from the plugin bootstrap file.
	 *
	 * @since 0.4.0
	 * @return self
	 */
	public static function init(): self {
		$loader = new self();
		$loader->add_namespace( 'ArchivedPostStatus', ARCHIVED_POST_STATUS_DIR . '/src' );
		$loader->register();

		require_once ARCHIVED_POST_STATUS_DIR . '/src/functions/functions.php';
		require_once ARCHIVED_POST_STATUS_DIR . '/src/functions/deprecated.php';

		return $loader;
	}
}
