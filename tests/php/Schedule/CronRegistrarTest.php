<?php
/**
 * Schedule\CronRegistrar Tests
 *
 * @since 0.5.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Schedule\CronRegistrar
 */

use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\CronRegistrar;

/**
 * @since 0.5.0
 * @covers ArchivedPostStatus\Schedule\CronRegistrar
 */
class CronRegistrarTest extends TestCase {

	/**
	 * @var CronRegistrar
	 */
	protected $registrar;

	public function set_up() {
		parent::set_up();
		$this->registrar = new CronRegistrar();
	}

	// -----------------------------------------------------------------------
	// hooks()
	// -----------------------------------------------------------------------

	/**
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::hooks
	 */
	public function test_hooks_registers_the_cron_schedules_filter_and_the_init_action() {
		$descriptors = $this->registrar->hooks();

		$this->assertCount( 2, $descriptors );

		$filter = $descriptors[0];
		$this->assertFalse( $filter->is_action() );
		$this->assertSame( 'cron_schedules', $filter->hook );

		$action = $descriptors[1];
		$this->assertTrue( $action->is_action() );
		$this->assertSame( 'init', $action->hook );
	}

	// -----------------------------------------------------------------------
	// recurring_hooks()
	// -----------------------------------------------------------------------

	/**
	 * Both recurring events this plugin schedules -- the sweep and the
	 * auto-archive stamp -- in registration order. `archived-post-status.php`'s
	 * deactivation hook reads exactly this list, so a hook missing here
	 * would leave an orphaned `wp_next_scheduled()` entry after deactivation.
	 *
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::recurring_hooks
	 */
	public function test_recurring_hooks_includes_the_sweep_and_stamp_hooks() {
		$this->assertSame(
			array(
				CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES,
				CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES,
			),
			CronRegistrar::recurring_hooks()
		);
	}

	/**
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar
	 */
	public function test_hook_apply_auto_archive_rules_constant_value() {
		$this->assertSame( 'aps_apply_auto_archive_rules', CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES );
	}

	// -----------------------------------------------------------------------
	// register_interval()
	// -----------------------------------------------------------------------

	/**
	 * The interval registered under the plugin's recurrence key is the
	 * filtered `aps_schedule_sweep_interval` value, not the hardcoded
	 * default -- a site shortening or lengthening the sweep must see that
	 * reflected in the actual cron_schedules entry WordPress schedules
	 * against.
	 *
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::register_interval
	 */
	public function test_register_interval_uses_the_filtered_seconds_value() {
		\WP_Mock::onFilter( 'aps_schedule_sweep_interval' )
			->with( 300 )
			->reply( 120 );

		$schedules = $this->registrar->register_interval( array() );

		$this->assertArrayHasKey( 'aps_sweep_interval', $schedules );
		$this->assertSame( 120, $schedules['aps_sweep_interval']['interval'] );
		$this->assertIsString( $schedules['aps_sweep_interval']['display'] );
	}

	/**
	 * Existing recurrences core or another plugin already registered must
	 * survive untouched -- register_interval() only adds its own entry.
	 *
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::register_interval
	 */
	public function test_register_interval_preserves_existing_schedules() {
		\WP_Mock::onFilter( 'aps_schedule_sweep_interval' )->with( 300 )->reply( 300 );

		$schedules = $this->registrar->register_interval(
			array( 'hourly' => array( 'interval' => 3600, 'display' => 'Once Hourly' ) )
		);

		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertArrayHasKey( 'aps_sweep_interval', $schedules );
	}

	// -----------------------------------------------------------------------
	// maybe_schedule_events()
	// -----------------------------------------------------------------------

	/**
	 * A missing sweep event is scheduled -- the self-repair path a site
	 * relies on after a bad deactivate or a host that flushed the cron
	 * option. The stamp event is already on the calendar in this test, so
	 * only the sweep event's own wp_schedule_event() call is expected.
	 *
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::maybe_schedule_events
	 */
	public function test_maybe_schedule_events_schedules_a_missing_sweep_event() {
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES )
			->andReturn( false );
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES )
			->andReturn( time() + 100 );

		\WP_Mock::userFunction( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'aps_sweep_interval', CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES )
			->andReturn( true );

		$this->registrar->maybe_schedule_events();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * A missing stamp event is scheduled on WordPress core's built-in
	 * `daily` recurrence -- no custom `cron_schedules` entry is needed for
	 * it, unlike the sweep event's filterable interval.
	 *
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::maybe_schedule_events
	 */
	public function test_maybe_schedule_events_schedules_a_missing_stamp_event_on_the_daily_recurrence() {
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES )
			->andReturn( time() + 100 );
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES )
			->andReturn( false );

		\WP_Mock::userFunction( 'wp_schedule_event' )
			->once()
			->with( \Mockery::type( 'int' ), 'daily', CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES )
			->andReturn( true );

		$this->registrar->maybe_schedule_events();

		$this->addToAssertionCount( 1 );
	}

	/**
	 * Both events already on the calendar are left alone -- scheduling
	 * either again would reset its next-run time every request.
	 *
	 * @covers ArchivedPostStatus\Schedule\CronRegistrar::maybe_schedule_events
	 */
	public function test_maybe_schedule_events_does_not_reschedule_an_existing_event() {
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( CronRegistrar::HOOK_RUN_SCHEDULED_ARCHIVES )
			->andReturn( time() + 100 );
		\WP_Mock::userFunction( 'wp_next_scheduled' )
			->with( CronRegistrar::HOOK_APPLY_AUTO_ARCHIVE_RULES )
			->andReturn( time() + 100 );

		\WP_Mock::userFunction( 'wp_schedule_event' )->never();

		$this->registrar->maybe_schedule_events();

		$this->addToAssertionCount( 1 );
	}
}
