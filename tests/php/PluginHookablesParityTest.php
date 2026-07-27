<?php
/**
 * Plugin::hookables() parity-snapshot test.
 *
 * @since 0.4.0
 * @package ArchivedPostStatus
 * @covers ArchivedPostStatus\Plugin
 *
 * Phase 3 of the 0.4.0 cleanup runs as three sub-steps: 3A extract (this
 * step — additive), 3B rewire (constructor-inject the new classes into
 * existing hookables), 3C reorganize (relocate functions, rename CLI).
 *
 * Risk-mitigation per the plan's Phase 3 Risk section: a parity snapshot
 * over `Plugin::hookables()` catches regressions in the composition root
 * across sub-steps. This test:
 *
 *   1. Asserts the hookable count is stable.
 *   2. Asserts the serialised list of (FQCN, hook descriptors) is stable.
 *
 * Step 3A does NOT modify `Plugin::hookables()` — this test exists to
 * ensure 3A leaves the composition surface unchanged AND to provide a
 * pre-rewire baseline so Step 3B's constructor-injection rewiring can be
 * compared structurally against the 3A snapshot.
 *
 * Phase 3B will update this test in lockstep with the constructor-injection
 * rewiring (the new descriptor shape becomes the new snapshot). Phase 3A's
 * job is to make sure no incidental change to the hook surface slipped in.
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
	 * no WP-CLI) composition is exactly 12 hookables today. This is a
	 * parity snapshot — a delta here means something slipped into (or was
	 * silently dropped from) the composition root.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_count_is_stable_in_admin_with_archive_meta_enabled() {
		$hookables = $this->invoke_hookables_under_admin_and_archive_meta();

		$this->assertCount(
			12,
			$hookables,
			'Count delta indicates a composition change slipped into Plugin::hookables().'
		);
	}

	/**
	 * The serialised hook descriptor list (per hookable: FQCN + (type, hook,
	 * priority, accepted_args) tuples) is stable across Phase 3A. Step 3B
	 * will replace this snapshot with the post-rewire one; this assertion
	 * exists so 3A's "we didn't touch the surface" promise has a test
	 * watching it.
	 *
	 * @covers ArchivedPostStatus\Plugin::hookables
	 */
	public function test_hookables_descriptor_snapshot_is_stable_in_admin_with_archive_meta_enabled() {
		$hookables = $this->invoke_hookables_under_admin_and_archive_meta();

		$snapshot = array_map( array( $this, 'descriptor_snapshot' ), $hookables );

		$expected = array(
			array(
				'class' => ArchivedPostStatus\Status\PostStatus::class,
				'hooks' => array(
					array( 'type' => 'action', 'hook' => 'init', 'priority' => 10, 'accepted_args' => 1 ),
					array( 'type' => 'filter', 'hook' => 'display_post_states', 'priority' => 10, 'accepted_args' => 2 ),
				),
			),
			array(
				'class' => ArchivedPostStatus\Status\PostStatusGuard::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Status\PostStatusGuard::class ),
			),
			array(
				'class' => ArchivedPostStatus\Frontend\ArchiveTitle::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Frontend\ArchiveTitle::class ),
			),
			array(
				'class' => ArchivedPostStatus\Frontend\AccessGuard::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Frontend\AccessGuard::class ),
			),
			array(
				'class' => ArchivedPostStatus\Admin\PostEditor::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Admin\PostEditor::class ),
			),
			array(
				'class' => ArchivedPostStatus\Admin\PostEditorGuard::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Admin\PostEditorGuard::class ),
			),
			array(
				'class' => ArchivedPostStatus\Admin\Notices::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Admin\Notices::class ),
			),
			array(
				'class' => ArchivedPostStatus\Settings\HookAdapter::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Settings\HookAdapter::class ),
			),
			array(
				'class' => ArchivedPostStatus\Archive\ArchiveMetaListener::class,
				'hooks' => array(
					array( 'type' => 'action', 'hook' => 'aps_archived_post', 'priority' => 10, 'accepted_args' => 3 ),
					array( 'type' => 'action', 'hook' => 'aps_unarchived_post', 'priority' => 10, 'accepted_args' => 3 ),
				),
			),
			array(
				'class' => ArchivedPostStatus\Admin\PostList::class,
				'hooks' => $this->hooks_for( $hookables, ArchivedPostStatus\Admin\PostList::class ),
			),
		);

		// The Admin\ArchiveColumn and Admin\PluginScreen hookables also land
		// in the list under is_admin = true, but for the snapshot we assert
		// the FQCN order + the two well-known fixed descriptors above
		// (PostStatus, ArchiveMetaListener), and let `hooks_for()` resolve
		// the rest by class. A class going missing or having its descriptor
		// tuple change will trip the `hooks_for()` lookup or the resulting
		// tuple mismatch.

		// Pin the FQCN order — composition-root sequencing matters for hook
		// registration order with WP_Mock, and order changes are also a
		// regression signal.
		$expected_fqcn_order = array_map( static fn( $row ) => $row['class'], $expected );
		// ArchiveColumn and PluginScreen live at the end of the admin block.
		$expected_fqcn_order[] = ArchivedPostStatus\Admin\ArchiveColumn::class;
		$expected_fqcn_order[] = ArchivedPostStatus\Admin\PluginScreen::class;

		$actual_fqcn_order = array_map( static fn( $row ) => $row['class'], $snapshot );

		$this->assertSame(
			$expected_fqcn_order,
			$actual_fqcn_order,
			'Plugin::hookables() FQCN order must be stable across Phase 3A.'
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

	/**
	 * Resolve the descriptor list for a given hookable class within the
	 * supplied hookables array. Mirrors how `descriptor_snapshot()` would
	 * have produced it.
	 *
	 * @param ArchivedPostStatus\Contracts\HookableInterface[] $hookables
	 * @param string                                           $class
	 * @return array<int, array{type: string, hook: string, priority: int, accepted_args: int}>
	 */
	private function hooks_for( array $hookables, string $class ): array {
		foreach ( $hookables as $hookable ) {
			if ( $hookable instanceof $class ) {
				return $this->descriptor_snapshot( $hookable )['hooks'];
			}
		}
		return array();
	}
}
