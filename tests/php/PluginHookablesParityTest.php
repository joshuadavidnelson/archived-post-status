<?php
/**
 * Plugin::hookables() parity-snapshot test.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Plugin
 *
 * A parity snapshot over `Plugin::hookables()` catches regressions in the
 * composition root:
 *
 *   1. Asserts the hookable count is stable.
 *   2. Asserts the FQCN order is stable, plus the fixed-shape hook
 *      descriptor tuples for `PostStatus` (which has no class-specific
 *      `hooks()` test of its own) and `ArchiveMetaListener` (which does,
 *      but that test never pins priority — this is the only place
 *      `ArchiveMetaListener`'s priority is locked).
 *
 * Any deliberate change to the wiring list (a new hookable, a reorder)
 * must update this snapshot in the same change — an unexpected diff here
 * means the composition surface moved without anyone deciding it should.
 */

/**
 * @since 0.4.0
 * @covers ArchivedPostStatus\Plugin
 */
class PluginHookablesParityTest extends TestCase {

	/**
	 * Invoke the private Plugin::hookables() method via reflection, with the
	 * environmental filters set so the full composition (non-CLI, admin,
	 * archive-meta-enabled) is returned.
	 *
	 * @return ArchivedPostStatus\Contracts\HookableInterface[]
	 */
	private function invoke_hookables_under_admin_and_archive_meta(): array {
		\WP_Mock::onFilter( 'aps_enable_archive_meta' )->with( true )->reply( true );
		\WP_Mock::userFunction( 'is_admin' )->andReturn( true );

		$plugin = new ArchivedPostStatus\Plugin( '0.4.0' );
		$method = new ReflectionMethod( ArchivedPostStatus\Plugin::class, 'hookables' );
		$method->setAccessible( true );

		return $method->invoke( $plugin );
	}

	/**
	 * Reduce a hookable instance to a deterministic descriptor tuple suitable
	 * for serialisation. Captures: FQCN of the hookable and the list of
	 * (type, hook, priority, accepted_args) tuples it declares. The callback
	 * field is deliberately omitted from the snapshot — it's a closure /
	 * array, not stable across instances.
	 *
	 * @param ArchivedPostStatus\Contracts\HookableInterface $hookable
	 * @return array{class: string, hooks: array<int, array{type: string, hook: string, priority: int, accepted_args: int}>}
	 */
	private function descriptor_snapshot( $hookable ): array {
		$hooks = array();
		foreach ( $hookable->hooks() as $descriptor ) {
			$hooks[] = array(
				'type'          => $descriptor->type,
				'hook'          => $descriptor->hook,
				'priority'      => $descriptor->priority,
				'accepted_args' => $descriptor->accepted_args,
			);
		}

		return array(
			'class' => $hookable::class,
			'hooks' => $hooks,
		);
	}

	/**
	 * The hookable list under the canonical (admin + archive-meta-enabled,
	 * no WP-CLI) composition is exactly 22 hookables today. This is a
	 * parity snapshot — a delta here means something slipped into (or was
	 * silently dropped from) the composition root.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_count_is_stable_in_admin_with_archive_meta_enabled() {
		$hookables = $this->invoke_hookables_under_admin_and_archive_meta();

		$this->assertCount(
			24,
			$hookables,
			'Count delta indicates a composition change slipped into Plugin::hookables().'
		);
	}

	/**
	 * Pins two things about `Plugin::hookables()` under the canonical
	 * (admin + archive-meta-enabled) composition: the FQCN order of the
	 * returned hookables, and the fixed-shape hook descriptor tuples for
	 * `PostStatus` and `ArchiveMetaListener`. `PostStatus` has no
	 * class-specific `hooks()` test of its own, so this is its only
	 * coverage. `ArchiveMetaListener` does have one
	 * (`ArchiveMetaListenerTest`, which pins hook names, `is_action()`,
	 * and `accepted_args`), but that test never pins priority — this is
	 * the only place `ArchiveMetaListener`'s priority is locked. Every
	 * other hookable's full descriptor list is pinned by its own
	 * `*Test.php` (e.g. `PostStatusGuardTest`, `AccessGuardTest`,
	 * `HookAdapterTest`); this test does not re-derive or re-assert those
	 * here.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_descriptor_snapshot_is_stable_in_admin_with_archive_meta_enabled() {
		$hookables = $this->invoke_hookables_under_admin_and_archive_meta();

		$snapshot = array_map( array( $this, 'descriptor_snapshot' ), $hookables );

		// Pin the FQCN order — composition-root sequencing matters for hook
		// registration order with WP_Mock, and order changes are also a
		// regression signal.
		$expected_fqcn_order = array(
			ArchivedPostStatus\Status\PostStatus::class,
			ArchivedPostStatus\Status\PostStatusGuard::class,
			ArchivedPostStatus\Frontend\ArchiveTitle::class,
			ArchivedPostStatus\Frontend\AccessGuard::class,
			ArchivedPostStatus\Admin\PostEditorGuard::class,
			ArchivedPostStatus\Settings\HookAdapter::class,
			// TermMetaRegistrar: registers term meta for REST, unconditional
			// like MetaRegistrar below -- a cron or REST request is neither
			// admin nor CLI (0.5.0 phase 8).
			ArchivedPostStatus\Settings\TermMetaRegistrar::class,
			ArchivedPostStatus\Archive\ArchiveMetaListener::class,
			// The unconditional schedule/cron block: fires on cron and REST
			// requests, neither is_admin() nor WP_CLI, so none of it can live
			// in either gated block below. CronQueueRunner appears twice --
			// once driving the sweep queue, once driving the auto-archive
			// stamp queue -- each a separate instance over a different
			// BatchProcessorInterface, per Plugin::hookables()'s own docblock.
			ArchivedPostStatus\Schedule\Queue\CronQueueRunner::class,
			ArchivedPostStatus\Schedule\Queue\CronQueueRunner::class,
			ArchivedPostStatus\Schedule\CronRegistrar::class,
			ArchivedPostStatus\Schedule\MetaRegistrar::class,
			ArchivedPostStatus\Schedule\ScheduleMetaListener::class,
			// PostEditor, Notices, PostList, PostActionHandler, ArchiveColumn,
			// ArchiveColumnSort, ScheduleColumn, ScheduleColumnSort, and
			// PluginScreen are the is_admin()-gated admin-only block.
			// PostEditor and Notices moved here in the perf
			// fix that gated them on is_admin() -- every hook either one
			// registers only fires on an actual wp-admin page load, so
			// hooking them on every request (front end, WP-CLI) was pure
			// overhead. PostEditorGuard stays in the unconditional spine
			// above -- its map_meta_cap filter runs on every capability
			// check anywhere, not just inside wp-admin. PostActionHandler
			// split out of PostList in the 0.4.0 restructor -- it shares
			// PostList's BulkActionHandler instance (see
			// PluginTest::test_hookables_construct_post_list_and_post_action_handler_with_the_same_bulk_action_handler_instance())
			// but owns the single-post action hooks independently.
			ArchivedPostStatus\Admin\PostEditor::class,
			ArchivedPostStatus\Admin\Notices::class,
			ArchivedPostStatus\Admin\PostList::class,
			ArchivedPostStatus\Admin\PostActionHandler::class,
			ArchivedPostStatus\Admin\ArchiveColumn::class,
			ArchivedPostStatus\Admin\ArchiveColumnSort::class,
			// ScheduleColumn / ScheduleColumnSort (0.5.0 phase 10): the
			// Scheduled column's visibility gate is the deliberate inversion
			// of ArchiveColumn's own -- see ScheduleColumn's class docblock.
			// Placed alongside the Archive column pair since both are
			// list-table column additions.
			ArchivedPostStatus\Admin\ScheduleColumn::class,
			ArchivedPostStatus\Admin\ScheduleColumnSort::class,
			ArchivedPostStatus\Admin\PluginScreen::class,
			// SettingsPage: the site settings screen (0.5.0) -- genuinely
			// admin-only, unlike the schedule/cron block above. TermFields
			// (0.5.0 phase 8) is admin-only too -- its own hooks() defers
			// further, to wp_loaded, but the hookable itself is constructed
			// here just like every other admin-only-set member.
			ArchivedPostStatus\Settings\SettingsPage::class,
			ArchivedPostStatus\Settings\TermFields::class,
		);

		$actual_fqcn_order = array_map( static fn( $row ) => $row['class'], $snapshot );

		$this->assertSame(
			$expected_fqcn_order,
			$actual_fqcn_order,
			'Plugin::hookables() FQCN order must match the locked snapshot.'
		);

		// Pin the two fixed-shape descriptor tuples that are most likely to
		// regress (PostStatus init + display_post_states, ArchiveMetaListener
		// 3-arg actions).
		$this->assertContains(
			array(
				'class' => ArchivedPostStatus\Status\PostStatus::class,
				'hooks' => array(
					array( 'type' => 'action', 'hook' => 'init', 'priority' => 10, 'accepted_args' => 1 ),
					array( 'type' => 'filter', 'hook' => 'display_post_states', 'priority' => 10, 'accepted_args' => 2 ),
				),
			),
			$snapshot,
			'PostStatus must register init + display_post_states with the locked 0.4.0 descriptors.'
		);

		$this->assertContains(
			array(
				'class' => ArchivedPostStatus\Archive\ArchiveMetaListener::class,
				'hooks' => array(
					array( 'type' => 'action', 'hook' => 'aps_archived_post', 'priority' => 10, 'accepted_args' => 3 ),
					array( 'type' => 'action', 'hook' => 'aps_unarchived_post', 'priority' => 10, 'accepted_args' => 3 ),
				),
			),
			$snapshot,
			'ArchiveMetaListener must register the locked 3-arg public hook signatures.'
		);
	}
}
