# Manual-test mu-plugin fixtures

PHP fixtures extracted from the 0.4.0 manual-test working docs before those
docs were retired in Phase 6.

Each file is a self-contained mu-plugin suitable for dropping into
`wp-content/mu-plugins/` to drive a specific scenario from the original
`tests/manual/0.4.0-e2e-results.md` walk. Preserved so the scenarios can be
re-run without re-deriving the fixture each time.

| File | Used by | Purpose |
|---|---|---|
| `aps-restrict-statuses.php` | E2E A1 | Filter `aps_archivable_statuses` to `['publish']` only |
| `aps-cap-filter.php` | E2E A6/A7 | Filter both `aps_default_archive_capability` and `aps_default_unarchive_capability` to `manage_options` |
| `aps-test-hooks.php` | E2E A9/A10 | Capture the three args fired by `aps_archived_post` and `aps_unarchived_post` into options |

The scenario walkthroughs (and their results) lived in `0.4.0-e2e-results.md`
which was retired in Phase 6 of the cleanup. See
`docs/0.4.0-cleanup-roadmap.md` for the consolidated summary of what those
scenarios verified.
