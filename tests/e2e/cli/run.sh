#!/usr/bin/env bash
#
# WP-CLI regression group for the 0.4.0 release.
#
# Runs `wp post archive` / `wp post unarchive` inside the wp-env tests container
# and asserts on BOTH the command exit code and the post status read back with
# `wp post get`. Exits non-zero if any assertion fails.
#
# Usage:
#   npm run test:e2e:cli
#   APS_CLI_CONTAINER=cli ./tests/e2e/cli/run.sh   # run against the dev site
#
# Fixtures used (see tests/e2e/fixtures/, all reset on exit):
#   aps_test_cap_filter_enabled         archive/unarchive capability -> manage_options
#   aps_test_restrict_statuses_enabled  archivable statuses -> publish only
#
set -uo pipefail

REPO_ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/../../.." && pwd )"
WP_ENV="${REPO_ROOT}/node_modules/.bin/wp-env"
CONTAINER="${APS_CLI_CONTAINER:-tests-cli}"
EDITOR_LOGIN="aps_editor"

cd "${REPO_ROOT}" || exit 1

if [ ! -x "${WP_ENV}" ]; then
	echo "wp-env not found at ${WP_ENV}; run npm install first." >&2
	exit 1
fi

STDERR_FILE="$( mktemp -t aps-cli-stderr )"
# Created ids are recorded in a file rather than an array: `create_post` is
# called from a command substitution, and an array append inside that subshell
# would never reach this process.
CREATED_IDS_FILE="$( mktemp -t aps-cli-ids )"
PASSED=0
FAILED=0

# ---------------------------------------------------------------------------
# Harness
# ---------------------------------------------------------------------------

# Run a `wp` command in the container. Sets WP_EXIT / WP_OUT / WP_ERR.
run_wp() {
	WP_OUT="$( "${WP_ENV}" run "${CONTAINER}" -- wp "$@" 2>"${STDERR_FILE}" )"
	WP_EXIT=$?
	WP_ERR="$( cat "${STDERR_FILE}" )"
}

pass() {
	PASSED=$(( PASSED + 1 ))
	printf '  ok   %s\n' "$1"
}

fail() {
	FAILED=$(( FAILED + 1 ))
	printf '  FAIL %s\n' "$1"
	printf '       %s\n' "$2"
}

assert_exit() {
	local expected="$1" label="$2"

	if [ "${WP_EXIT}" = "${expected}" ]; then
		pass "${label} (exit ${WP_EXIT})"
	else
		fail "${label}" "expected exit ${expected}, got ${WP_EXIT}: ${WP_ERR}"
	fi
}

assert_exit_nonzero() {
	local label="$1"

	if [ "${WP_EXIT}" != "0" ]; then
		pass "${label} (exit ${WP_EXIT})"
	else
		fail "${label}" "expected a non-zero exit, got 0: ${WP_OUT}"
	fi
}

assert_eq() {
	local expected="$1" actual="$2" label="$3"

	if [ "${expected}" = "${actual}" ]; then
		pass "${label} (${actual})"
	else
		fail "${label}" "expected '${expected}', got '${actual}'"
	fi
}

assert_contains() {
	local haystack="$1" needle="$2" label="$3"

	case "${haystack}" in
		*"${needle}"*) pass "${label}" ;;
		*) fail "${label}" "expected to find '${needle}' in: ${haystack}" ;;
	esac
}

# Echo a post's current status.
post_status() {
	"${WP_ENV}" run "${CONTAINER}" -- wp post get "$1" --field=post_status 2>/dev/null
}

assert_status() {
	assert_eq "$1" "$( post_status "$2" )" "$3"
}

# Create a post and remember it for teardown. Echoes the new id.
create_post() {
	local status="$1" title="$2" id

	id="$( "${WP_ENV}" run "${CONTAINER}" -- wp post create \
		--post_type=post --post_status="${status}" --post_title="${title}" \
		--comment_status=open --ping_status=open --porcelain 2>/dev/null )"

	echo "${id}" >>"${CREATED_IDS_FILE}"
	echo "${id}"
}

# Create N posts in ONE container call and remember them for teardown.
# Echoes the new ids, comma separated.
#
# The progress-bar branch of CommandRunner only engages above 20 ids, so that
# case needs 21 posts; created one at a time that would cost ~25s of Docker
# startup on its own.
create_posts() {
	local count="$1" status="$2" title="$3" ids

	ids="$( "${WP_ENV}" run "${CONTAINER}" -- wp eval "
		\$ids = array();
		for ( \$i = 1; \$i <= ${count}; \$i++ ) {
			\$ids[] = wp_insert_post( array(
				'post_type'   => 'post',
				'post_status' => '${status}',
				'post_title'  => '${title} ' . \$i,
			) );
		}
		echo implode( ',', \$ids );
	" 2>/dev/null )"

	echo "${ids}" | tr ',' '\n' >>"${CREATED_IDS_FILE}"
	echo "${ids}"
}

# Echo how many of the given (comma-separated) ids carry the archived status.
count_archived() {
	"${WP_ENV}" run "${CONTAINER}" -- wp eval "
		\$archived = 0;
		foreach ( array( $1 ) as \$id ) {
			if ( 'archive' === get_post_status( \$id ) ) {
				\$archived++;
			}
		}
		echo \$archived;
	" 2>/dev/null
}

set_option() {
	"${WP_ENV}" run "${CONTAINER}" -- wp option update "$1" "$2" >/dev/null 2>&1
}

delete_option() {
	"${WP_ENV}" run "${CONTAINER}" -- wp option delete "$1" >/dev/null 2>&1
}

cleanup() {
	local ids=()

	delete_option aps_test_cap_filter_enabled
	delete_option aps_test_restrict_statuses_enabled

	while IFS= read -r id; do
		[ -n "${id}" ] && ids+=( "${id}" )
	done <"${CREATED_IDS_FILE}"

	if [ ${#ids[@]} -gt 0 ]; then
		# One `wp eval` rather than `wp post delete <ids>`: WP-CLI aborts the
		# whole batch on the first id it cannot delete, which would strand the
		# rest. Teardown has to be tolerant.
		local csv
		csv="$( IFS=,; echo "${ids[*]}" )"

		"${WP_ENV}" run "${CONTAINER}" -- wp eval \
			"foreach ( array( ${csv} ) as \$id ) { wp_delete_post( \$id, true ); }" \
			>/dev/null 2>&1
	fi

	rm -f "${STDERR_FILE}" "${CREATED_IDS_FILE}"
}
trap cleanup EXIT

section() {
	printf '\n%s\n' "$1"
}

# ---------------------------------------------------------------------------
# 1. `--user` respects aps_default_archive_capability (the A6/A7 class)
# ---------------------------------------------------------------------------

section '1. capability filter is enforced for an authenticated CLI user'

POST_CAP_OK="$( create_post publish 'CLI cap allowed' )"
POST_CAP_DENIED="$( create_post publish 'CLI cap denied' )"

# Stock capability (edit_others_posts): the editor is allowed.
run_wp post archive "${POST_CAP_OK}" --user="${EDITOR_LOGIN}"
assert_exit 0 'editor archives with the default capability'
assert_status archive "${POST_CAP_OK}" 'post is archived'

# Filtered to manage_options: the same editor is refused.
set_option aps_test_cap_filter_enabled 1

run_wp post archive "${POST_CAP_DENIED}" --user="${EDITOR_LOGIN}"
assert_exit_nonzero 'editor is refused once the capability is filtered'
assert_contains "${WP_ERR}" 'does not have capability to archive' 'refusal names the capability check'
assert_status publish "${POST_CAP_DENIED}" 'refused post keeps its status'

# The unarchive capability filter is enforced on the same terms.
run_wp post unarchive "${POST_CAP_OK}" --user="${EDITOR_LOGIN}"
assert_exit_nonzero 'editor is refused unarchive once the capability is filtered'
assert_contains "${WP_ERR}" 'does not have capability to unarchive' 'refusal names the unarchive check'
assert_status archive "${POST_CAP_OK}" 'refused post is still archived'

# ---------------------------------------------------------------------------
# 2. Anonymous CLI bypasses the capability gate (documented behaviour)
# ---------------------------------------------------------------------------

section '2. anonymous CLI bypasses the capability gate'

# The filter from section 1 is still on, so this only passes if the gate really
# is skipped when there is no current user (Command::capability_check()).
run_wp post archive "${POST_CAP_DENIED}"
assert_exit 0 'anonymous CLI archives despite the manage_options filter'
assert_status archive "${POST_CAP_DENIED}" 'post is archived'

run_wp post unarchive "${POST_CAP_DENIED}"
assert_exit 0 'anonymous CLI unarchives despite the filter'
assert_status publish "${POST_CAP_DENIED}" 'post is restored to its previous status'

delete_option aps_test_cap_filter_enabled

# ---------------------------------------------------------------------------
# 3. Multiple ids in one invocation
# ---------------------------------------------------------------------------

section '3. multi-id invocation'

MULTI_ONE="$( create_post publish 'CLI multi one' )"
MULTI_TWO="$( create_post draft 'CLI multi two' )"
MULTI_THREE="$( create_post pending 'CLI multi three' )"

run_wp post archive "${MULTI_ONE}" "${MULTI_TWO}" "${MULTI_THREE}"
assert_exit 0 'three ids archive in one invocation'
assert_contains "${WP_OUT}" 'archived post' 'per-post output is emitted below the count limit'
assert_status archive "${MULTI_ONE}" 'first post archived'
assert_status archive "${MULTI_TWO}" 'second post archived'
assert_status archive "${MULTI_THREE}" 'third post archived'

run_wp post unarchive "${MULTI_ONE}" "${MULTI_TWO}" "${MULTI_THREE}"
assert_exit 0 'three ids unarchive in one invocation'
assert_status publish "${MULTI_ONE}" 'first post restored to publish'
assert_status draft "${MULTI_TWO}" 'second post restored to draft'
assert_status pending "${MULTI_THREE}" 'third post restored to pending'

# ---------------------------------------------------------------------------
# 3b. The exit status reflects ANY failed item, at any batch size
#
# CommandRunner::run() accumulates the per-item status with max(), so the exit
# code is independent of ordering and of which output branch ran. Both cases
# below exited 0 before that fix (phase-2b Finding 1); the >20-id case is the
# more dangerous one, because ordering cannot rescue it.
# ---------------------------------------------------------------------------

section '3b. exit status reflects any failed item'

MIXED_FAIL="$( create_post publish 'CLI mixed failing' )"
MIXED_OK="$( create_post publish 'CLI mixed ok' )"

# An already-archived post is the cheapest deterministic failure: ArchiveCommand
# rejects it before any capability work.
run_wp post archive "${MIXED_FAIL}"
assert_exit 0 'seed: the failing id is archived up front'

run_wp post archive "${MIXED_FAIL}" "${MIXED_OK}"
assert_exit_nonzero 'a batch whose FIRST item fails exits non-zero'
assert_contains "${WP_ERR}" 'is already archived' 'the failure is reported'
assert_status archive "${MIXED_OK}" 'the later valid id is still archived'

# Above the count limit the per-post emit is replaced by a progress bar. The
# exit code must survive that swap.
BULK_IDS="$( create_posts 21 publish 'CLI bulk' )"
BULK_FAIL="${BULK_IDS%%,*}"

run_wp post archive "${BULK_FAIL}"
assert_exit 0 'seed: one id inside the large batch is already archived'

# shellcheck disable=SC2086 -- deliberate word splitting: 21 positional ids.
run_wp post archive ${BULK_IDS//,/ }
assert_exit_nonzero 'a 21-id batch containing one failure exits non-zero'
assert_eq '' "${WP_OUT}" 'per-post output is suppressed above the count limit'
assert_eq 21 "$( count_archived "${BULK_IDS}" )" 'every valid id in the large batch is archived'

# ---------------------------------------------------------------------------
# 4. --force skips the archivable-status whitelist
# ---------------------------------------------------------------------------

section '4. --force'

FORCE_POST="$( create_post draft 'CLI force draft' )"

set_option aps_test_restrict_statuses_enabled 1

run_wp post archive "${FORCE_POST}"
assert_exit_nonzero 'a non-archivable status is refused'
assert_contains "${WP_ERR}" 'is not an archivable status' 'refusal names the status check'
assert_status draft "${FORCE_POST}" 'refused post keeps its status'

run_wp post archive "${FORCE_POST}" --force
assert_exit 0 '--force archives the same post'
assert_status archive "${FORCE_POST}" 'forced post is archived'

delete_option aps_test_restrict_statuses_enabled

# An already-archived post is refused before any capability work.
run_wp post archive "${FORCE_POST}"
assert_exit_nonzero 'archiving an archived post is refused'
assert_contains "${WP_ERR}" 'is already archived' 'refusal says the post is already archived'

# ---------------------------------------------------------------------------
# 5. --status overrides the restored status on unarchive
# ---------------------------------------------------------------------------

section '5. --status on unarchive'

STATUS_POST="$( create_post publish 'CLI status override' )"

run_wp post archive "${STATUS_POST}"
assert_exit 0 'post archived before the override'

run_wp post unarchive "${STATUS_POST}" --status=draft
assert_exit 0 'unarchive with --status succeeds'
assert_status draft "${STATUS_POST}" '--status wins over the recorded previous status'

# ---------------------------------------------------------------------------
# 6. --defer-term-counting runs, and always restores term counting
# ---------------------------------------------------------------------------

section '6. --defer-term-counting'

DEFER_POST="$( create_post publish 'CLI defer counting' )"

run_wp post archive "${DEFER_POST}" --defer-term-counting
assert_exit 0 '--defer-term-counting archives the post'
assert_status archive "${DEFER_POST}" 'deferred-counting post is archived'

run_wp post unarchive "${DEFER_POST}" --defer-term-counting
assert_exit 0 '--defer-term-counting unarchives the post'
assert_status publish "${DEFER_POST}" 'deferred-counting post is restored'

# The try/finally guardrail can only be observed inside the process that set the
# flag, so the two cases below run the command object directly under `wp eval`.
# `wp_defer_term_counting()` with no argument returns the current state.
DEFER_SUCCESS_POST="$( create_post publish 'CLI defer success' )"

run_wp eval "
\$command = new \\ArchivedPostStatus\\CLI\\ArchiveCommand();
\$result  = \$command->run( ${DEFER_SUCCESS_POST}, array( 'defer-term-counting' => true ) );
echo wp_json_encode( array(
	'success'  => \$result->is_success,
	'deferred' => wp_defer_term_counting(),
) );
"
assert_exit 0 'eval harness ran for the success path'
assert_eq '{"success":true,"deferred":false}' "${WP_OUT}" 'term counting is restored after a successful archive'

DEFER_FAILURE_POST="$( create_post publish 'CLI defer failure' )"

run_wp eval "
add_filter( 'aps_pre_archive_post', '__return_false' );
\$command = new \\ArchivedPostStatus\\CLI\\ArchiveCommand();
\$result  = \$command->run( ${DEFER_FAILURE_POST}, array( 'defer-term-counting' => true ) );
echo wp_json_encode( array(
	'success'  => \$result->is_success,
	'deferred' => wp_defer_term_counting(),
) );
"
assert_exit 0 'eval harness ran for the failure path'
assert_eq '{"success":false,"deferred":false}' "${WP_OUT}" 'term counting is restored after a failed archive'
assert_status publish "${DEFER_FAILURE_POST}" 'vetoed post keeps its status'

# ---------------------------------------------------------------------------

section "Result: ${PASSED} passed, ${FAILED} failed."

if [ "${FAILED}" -gt 0 ]; then
	exit 1
fi

exit 0
