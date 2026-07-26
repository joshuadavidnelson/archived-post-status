<?php
/**
 * Loader Tests
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Loader
 *
 * Smoke + path-resolution coverage for the PSR-4-style autoloader.
 *
 * Phase 3.6 refactor: most tests now go through the public `Loader::init()`
 * entry point. Phase 5 of the 0.4.0 cleanup tightened `add_namespace()` back
 * to private (its only legitimate caller is `init()`), so tests that need to
 * layer a test-only namespace use the reflection helper
 * {@see add_namespace_via_reflection()} below. The private-constructor
 * reflection is retained for the one test that needs an isolated loader (no
 * SPL registration, no production namespace) — see
 * `test_load_class_returns_silently_for_unregistered_namespace`.
 */

use ArchivedPostStatus\Loader;

/**
 * Loader test case.
 *
 * @since 0.4.0
 * @covers ArchivedPostStatus\Loader
 */
class LoaderTest extends TestCase {

	/** @var string Created during tests; deleted in tear_down. */
	private string $tmp_dir = '';

	/** @var array<int, callable> Autoload callbacks registered by tests that need unregistration on teardown. */
	private array $registered_autoloaders = array();

	public function tear_down() {
		if ( '' !== $this->tmp_dir && is_dir( $this->tmp_dir ) ) {
			$this->remove_dir( $this->tmp_dir );
		}

		// Unregister anything we pushed onto the SPL stack so the loader
		// doesn't leak across tests (this matters when init() runs multiple
		// times — each call adds a fresh callable).
		foreach ( $this->registered_autoloaders as $callback ) {
			spl_autoload_unregister( $callback );
		}
		$this->registered_autoloaders = array();

		parent::tear_down();
	}

	/**
	 * Recursively delete a directory tree.
	 */
	private function remove_dir( string $dir ): void {
		foreach ( scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Call Loader::init() and track the registered SPL callback so
	 * tear_down() can unregister it. Returns the configured Loader.
	 */
	private function init_tracked(): Loader {
		$loader = Loader::init();
		$this->registered_autoloaders[] = array( $loader, 'load_class' );
		return $loader;
	}

	/**
	 * Construct an isolated Loader (no namespaces registered, not on the
	 * SPL stack) via reflection. Only one test needs this — when verifying
	 * the "unregistered namespace returns silently" branch we cannot use
	 * a Loader::init() instance because that one always has the production
	 * ArchivedPostStatus → src/ mapping registered. The reflection escape
	 * hatch gives us a clean-slate loader for that single scenario.
	 */
	private function isolated_loader(): Loader {
		$ref         = new ReflectionClass( Loader::class );
		$constructor = $ref->getConstructor();
		$constructor->setAccessible( true );
		$loader = $ref->newInstanceWithoutConstructor();
		$constructor->invoke( $loader );
		return $loader;
	}

	/**
	 * Reach in via reflection to register a test-only namespace on the
	 * loader. `add_namespace()` is private (its only legitimate caller is
	 * `Loader::init()`); the reflection escape hatch is the test-only seam
	 * that lets us layer fixtures onto a real loader instance without
	 * having to duplicate the bootstrap pipeline.
	 */
	private function add_namespace_via_reflection( Loader $loader, string $prefix, string $base_dir ): void {
		$method = new ReflectionMethod( Loader::class, 'add_namespace' );
		$method->setAccessible( true );
		$method->invoke( $loader, $prefix, $base_dir );
	}

	/**
	 * Create a tmp directory with a sentinel class file that load_class()
	 * can resolve and require.
	 */
	private function create_tmp_namespace( string $relative_class_path, string $class_name ): string {
		$this->tmp_dir = sys_get_temp_dir() . '/aps-loader-test-' . uniqid();
		$full_path     = $this->tmp_dir . '/' . dirname( $relative_class_path );
		if ( ! is_dir( $full_path ) ) {
			mkdir( $full_path, 0777, true );
		}
		$file = $this->tmp_dir . '/' . $relative_class_path;
		file_put_contents(
			$file,
			"<?php\nnamespace Aps\\LoaderTest;\nclass {$class_name} {}\n"
		);
		return $this->tmp_dir;
	}

	/**
	 * add_namespace() registers the prefix → base directory mapping so
	 * subsequent load_class() calls in that namespace resolve to files
	 * under the base directory.
	 *
	 * Verified end-to-end: layer a test namespace on top of the
	 * Loader::init() instance, then resolve a known class under it. If
	 * add_namespace didn't store the mapping, load_class would fall
	 * through silently and the class wouldn't become declared.
	 *
	 * @covers ArchivedPostStatus\Loader::add_namespace
	 * @covers ArchivedPostStatus\Loader::load_class
	 */
	public function test_add_namespace_enables_load_class_to_resolve_files() {
		$base   = $this->create_tmp_namespace( 'AddNamespaceFixture.php', 'AddNamespaceFixture' );
		$loader = $this->init_tracked();
		$this->add_namespace_via_reflection( $loader, 'Aps\\LoaderTest', $base );

		$class = 'Aps\\LoaderTest\\AddNamespaceFixture';
		$this->assertFalse( class_exists( $class, false ) );

		$loader->load_class( $class );

		$this->assertTrue( class_exists( $class, false ) );
	}

	/**
	 * load_class() returns silently when the class FQCN doesn't match
	 * any registered namespace prefix. No file is required; no error
	 * is raised. This is the autoloader fall-through contract.
	 *
	 * This test uses the reflection-based isolated loader (rather than
	 * Loader::init()) because we need a clean-slate instance whose ONLY
	 * registered namespace is the one we control — otherwise the
	 * production `ArchivedPostStatus` mapping would absorb any class
	 * name that happened to start with that prefix, masking the fall-
	 * through behavior we want to pin.
	 *
	 * @covers ArchivedPostStatus\Loader::load_class
	 */
	public function test_load_class_returns_silently_for_unregistered_namespace() {
		$loader = $this->isolated_loader();
		$this->add_namespace_via_reflection( $loader, 'Aps\\Registered', '/nonexistent/path' );

		// No prefix matches → silent return; no class becomes declared.
		$loader->load_class( 'Some\\Unregistered\\Klass' );

		$this->assertFalse( class_exists( 'Some\\Unregistered\\Klass', false ) );
	}

	/**
	 * load_class() returns silently when the resolved file path doesn't
	 * exist on disk. Important so a mistyped class name never crashes
	 * the request.
	 *
	 * @covers ArchivedPostStatus\Loader::load_class
	 */
	public function test_load_class_returns_silently_when_resolved_file_missing() {
		$loader = $this->init_tracked();
		$this->add_namespace_via_reflection( $loader, 'Aps\\GhostNs', sys_get_temp_dir() );

		// File doesn't exist; load_class() must not warn.
		$loader->load_class( 'Aps\\GhostNs\\NonexistentClass' );

		$this->assertFalse( class_exists( 'Aps\\GhostNs\\NonexistentClass', false ) );
	}

	/**
	 * load_class() translates backslashes in the namespace suffix into
	 * forward-slash directory separators when resolving the file path.
	 *
	 * @covers ArchivedPostStatus\Loader::load_class
	 */
	public function test_load_class_translates_namespace_separators_to_subdirectories() {
		$base   = $this->create_tmp_namespace( 'Sub/Dir/NestedFixture.php', 'NestedFixture' );
		$loader = $this->init_tracked();
		$this->add_namespace_via_reflection( $loader, 'Aps\\LoaderTest', $base );

		// Override the test fixture: file contents declare the class under
		// the deeper namespace path, matching the directory layout.
		file_put_contents(
			$base . '/Sub/Dir/NestedFixture.php',
			"<?php\nnamespace Aps\\LoaderTest\\Sub\\Dir;\nclass NestedFixture {}\n"
		);

		$class = 'Aps\\LoaderTest\\Sub\\Dir\\NestedFixture';
		$loader->load_class( $class );

		$this->assertTrue( class_exists( $class, false ) );
	}

	/**
	 * register() pushes load_class onto the SPL autoloader stack. After
	 * register() returns, an autoload event for a class under a
	 * registered namespace triggers the loader.
	 *
	 * Loader::init() calls register() internally, so this test asserts
	 * that the stack count grew after init() and that an unloaded class
	 * under a tmp namespace becomes declared via the autoload event.
	 *
	 * @covers ArchivedPostStatus\Loader::init
	 * @covers ArchivedPostStatus\Loader::register
	 */
	public function test_register_pushes_load_class_onto_spl_autoload_stack() {
		$base   = $this->create_tmp_namespace( 'RegisterFixture.php', 'RegisterFixture' );
		$before = spl_autoload_functions();

		$loader = $this->init_tracked();
		$this->add_namespace_via_reflection( $loader, 'Aps\\LoaderTest', $base );

		$after = spl_autoload_functions();

		$this->assertGreaterThan( count( (array) $before ), count( (array) $after ) );

		// Trigger the autoloader by referencing an unloaded class.
		$class = 'Aps\\LoaderTest\\RegisterFixture';
		$this->assertTrue( class_exists( $class ) );
	}

	/**
	 * register() is idempotent: a second call is a no-op so accidental
	 * double-bootstrapping doesn't stack two identical callbacks on the SPL
	 * stack. Asserts the autoload-function count is unchanged after the
	 * second register() invocation.
	 *
	 * @covers ArchivedPostStatus\Loader::register
	 */
	public function test_register_is_idempotent_on_second_call() {
		$loader = $this->init_tracked();

		$count_after_first = count( (array) spl_autoload_functions() );

		$loader->register();

		$count_after_second = count( (array) spl_autoload_functions() );

		$this->assertSame( $count_after_first, $count_after_second );
	}

	/**
	 * Loader::init() is the one bootstrap entrypoint. It returns a
	 * configured loader, registers the ArchivedPostStatus → src/ mapping,
	 * and requires functions.php. Verifying the round trip — init()
	 * returns a Loader instance — locks the bootstrap contract.
	 *
	 * @covers ArchivedPostStatus\Loader::init
	 */
	public function test_init_returns_configured_loader_instance() {
		$loader = $this->init_tracked();

		$this->assertInstanceOf( Loader::class, $loader );
	}
}
