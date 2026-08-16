<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\TermMeta;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Hooks\HookLoader;
use WP_Term;

/**
 * Renders and saves the term-level auto-archive rule on the add/edit term
 * screens, for every opted-in taxonomy.
 *
 * Admin-only: unlike {@see TermMetaRegistrar}, none of this class's hooks
 * fire outside wp-admin.
 *
 * `hooks()` only registers a `wp_loaded` deferral, mirroring
 * {@see \ArchivedPostStatus\Admin\ArchiveColumn}'s own per-post-type
 * pattern: `hooks()` runs on `plugins_loaded`, before custom taxonomies
 * register on `init`, so building `"{$taxonomy}_add_form_fields"`-shaped
 * hook names there would silently skip every custom taxonomy registered
 * after that point. `wp_loaded` postdates every `init` priority.
 *
 * Reuses the nonce WordPress already renders on these forms —
 * `_wpnonce_add-tag` (action `add-tag`) on the add-term form,
 * `_wpnonce` (action `update-tag_{$term_id}`) on the edit-term screen —
 * rather than rendering a second, redundant nonce field of its own.
 *
 * @since 0.5.0
 */
final class TermFields implements HookableInterface {

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructor.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'wp_loaded', array( $this, 'register_taxonomy_hooks' ) ),
		);
	}

	/**
	 * Register the per-taxonomy form-field and save hooks once taxonomies
	 * are guaranteed to exist.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function register_taxonomy_hooks(): void {
		( new HookLoader() )->register( $this->taxonomy_hooks() );
	}

	/**
	 * Build the form-field and save hook descriptors for each opted-in,
	 * currently-registered taxonomy. A configured slug whose taxonomy no
	 * longer exists (a deactivated custom-taxonomy plugin, a typo cleared
	 * from the sanitizer on the next settings save but not yet re-saved) is
	 * skipped rather than registering hooks WordPress will simply never
	 * fire.
	 *
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	private function taxonomy_hooks(): array {
		$descriptors = array();

		foreach ( self::taxonomies() as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$descriptors[] = HookDescriptor::action( "{$taxonomy}_add_form_fields", array( $this, 'render_add_form_fields' ) );
			$descriptors[] = HookDescriptor::action( "{$taxonomy}_edit_form_fields", array( $this, 'render_edit_form_fields' ), 10, 2 );
			$descriptors[] = HookDescriptor::action( "created_{$taxonomy}", array( $this, 'save' ) );
			$descriptors[] = HookDescriptor::action( "edited_{$taxonomy}", array( $this, 'save' ) );
		}

		return $descriptors;
	}

	/**
	 * The configured `auto_archive_taxonomies` opt-in list, read the same
	 * way {@see \ArchivedPostStatus\AutoArchive\Provider\TermRuleProvider}
	 * reads its own settings.
	 *
	 * @since 0.5.0
	 * @return string[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- Schema is the canonical settings-table accessor.
	 */
	private static function taxonomies(): array {
		$taxonomies = apply_filters( 'aps_auto_archive_taxonomies', Schema::default_for( 'auto_archive_taxonomies' ) );

		return is_array( $taxonomies ) ? $taxonomies : array();
	}

	// -----------------------------------------------------------------------
	// Rendering
	// -----------------------------------------------------------------------

	/**
	 * `{$taxonomy}_add_form_fields` callback.
	 *
	 * @since 0.5.0
	 * @param string $taxonomy The taxonomy slug.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- TermCapability is the canonical term-capability accessor.
	 */
	public function render_add_form_fields( string $taxonomy ): void {
		if ( ! TermCapability::granted( $taxonomy ) ) {
			return;
		}

		echo '<div class="form-field">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped HTML, see CascadeField's own no-double-escape contract.
		echo self::render_cascade_field( null );
		echo '</div>';
	}

	/**
	 * `{$taxonomy}_edit_form_fields` callback.
	 *
	 * @since 0.5.0
	 * @param WP_Term $term     The term being edited.
	 * @param string  $taxonomy The taxonomy slug (unused; part of the locked
	 *                          `{$taxonomy}_edit_form_fields` signature —
	 *                          `$term->taxonomy` carries the same value).
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.UnusedFormalParameter") -- locked `{$taxonomy}_edit_form_fields` signature.
	 * @SuppressWarnings("PHPMD.StaticAccess") -- TermCapability is the canonical term-capability accessor.
	 */
	public function render_edit_form_fields( WP_Term $term, string $taxonomy ): void {
		if ( ! TermCapability::granted( $term->taxonomy ) ) {
			return;
		}

		echo '<tr class="form-field"><td colspan="2">';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped HTML, see CascadeField's own no-double-escape contract.
		echo self::render_cascade_field( $term->term_id );
		echo '</td></tr>';
	}

	/**
	 * Render the shared CascadeField for either form.
	 *
	 * @since 0.5.0
	 * @param ?int $term_id The term being edited, or null on the add-new form.
	 * @return string Escaped HTML.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/value-object accessors.
	 */
	private static function render_cascade_field( ?int $term_id ): string {
		$meta = null === $term_id ? new TermMeta( null, ChildMode::Open ) : TermMeta::for_term( $term_id );

		return CascadeField::render(
			'aps_auto_archive_days',
			Schema::label_for( 'auto_archive_days' ),
			Schema::description_for( 'auto_archive_days' ),
			TermInheritance::resolve(),
			$meta->days,
			new DownstreamSelector(
				'aps_auto_archive_child_mode',
				__( 'posts', 'archived-post-status' ),
				$meta->child_mode
			)
		);
	}

	// -----------------------------------------------------------------------
	// Saving
	// -----------------------------------------------------------------------

	/**
	 * `created_{$taxonomy}` / `edited_{$taxonomy}` callback.
	 *
	 * Verifies the nonce WordPress already renders on the add/edit term
	 * forms — see the class docblock — before checking capability and
	 * writing. When an ancestor freezes the cascade
	 * ({@see CascadeInheritance::frozen()}), this level's control was never
	 * rendered for the user to submit, so the stored rule is left exactly
	 * as it is rather than being wiped by an empty submission — this also
	 * blocks a forged POST body from setting a term override while the
	 * ancestor chain is locked or off.
	 *
	 * @since 0.5.0
	 * @param int $term_id The term ID that was created or edited.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- TermCapability/TermInheritance are canonical accessors.
	 */
	public function save( int $term_id ): void {
		$term = get_term( $term_id );

		if ( ! $term instanceof WP_Term ) {
			return;
		}

		if ( ! self::nonce_valid( $term_id ) ) {
			return;
		}

		if ( ! TermCapability::granted( $term->taxonomy ) ) {
			return;
		}

		if ( TermInheritance::resolve()->frozen() ) {
			return;
		}

		self::save_from_request( $term_id );
	}

	/**
	 * Verify whichever of WordPress's own two term-form nonces is present:
	 * `_wpnonce_add-tag` (add form, action `add-tag`) or `_wpnonce` (edit
	 * screen, action `update-tag_{$term_id}`). Neither present, or present
	 * but invalid, fails closed — this also covers `created_{$taxonomy}` /
	 * `edited_{$taxonomy}` firing from a context with no form submission at
	 * all (REST, WP-CLI, an import), where writing anything here would be
	 * unauthenticated.
	 *
	 * @since 0.5.0
	 * @param int $term_id The term ID being saved.
	 * @return bool
	 */
	private static function nonce_valid( int $term_id ): bool {
		if ( isset( $_POST['_wpnonce_add-tag'] ) ) {
			return false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ), 'add-tag' );
		}

		if ( isset( $_POST['_wpnonce'] ) ) {
			return false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'update-tag_' . $term_id );
		}

		return false;
	}

	/**
	 * Sanitize and save the submitted days/child_mode pair, reusing the
	 * site-level {@see Schema} sanitizers rather than re-implementing the
	 * "floor at 1" / "unrecognized enum falls back to Open" rules a second
	 * time. An empty submitted days value is normalized to `null` before
	 * the sanitizer runs — the sanitizer's own `null` passthrough is what
	 * lets a blank field mean "no override", not "0 days".
	 *
	 * @since 0.5.0
	 * @param int $term_id The term ID to save to.
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table accessor.
	 */
	private static function save_from_request( int $term_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save() before this method is ever called, see nonce_valid(); the raw value below is sanitized on the next line via Schema's own sanitizer.
		$raw_days = isset( $_POST['aps_auto_archive_days'] ) ? wp_unslash( $_POST['aps_auto_archive_days'] ) : '';
		$raw_days = '' === trim( (string) $raw_days ) ? null : $raw_days;

		$days = ( Schema::sanitizer_for( 'auto_archive_days' ) )( $raw_days );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in save() before this method is ever called, see nonce_valid(). sanitize_key() below sanitizes the raw value inline.
		$raw_child_mode = isset( $_POST['aps_auto_archive_child_mode'] ) ? sanitize_key( wp_unslash( $_POST['aps_auto_archive_child_mode'] ) ) : '';
		$child_mode     = ChildMode::tryFrom( ( Schema::sanitizer_for( 'auto_archive_child_mode' ) )( $raw_child_mode ) ) ?? ChildMode::Open;

		( new TermMeta( $days, $child_mode ) )->save( $term_id );
	}
}
