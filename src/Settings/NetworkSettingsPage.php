<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;

/**
 * The network settings screen: `Network Admin → Settings → Archived Post
 * Status`. Only registered when the plugin is network-activated
 * ({@see NetworkActivation}) — see {@see \ArchivedPostStatus\Plugin::hookables()}.
 *
 * The network level is the top of the cascade (plan §4.6): it inherits
 * nothing, so its {@see CascadeField} call always passes
 * {@see CascadeInheritance::none()}, and it always has the site level below
 * it, so it always supplies a {@see DownstreamSelector}.
 *
 * **The one hand-rolled form handler in this release.** `options.php` /
 * `register_setting()` — what {@see SettingsPage} uses for the site screen
 * — does not handle network options at all; the Settings API has no
 * network-option equivalent. This screen instead posts to
 * `network_admin_edit.php?action=` . self::SAVE_ACTION, which core
 * dispatches to {@see handle_save()} via the `network_admin_edit_{$action}`
 * hook, and that method is responsible end to end for its own nonce check,
 * capability check, sanitization, write, and redirect — none of that comes
 * for free the way it does for `options.php`.
 *
 * **Network-scoped sanitization.** {@see Sanitizer::sanitize()} is
 * level-blind by design (see {@see Store::defaults()}'s docblock for why
 * that was safe for the site option before this phase) — it sanitizes
 * whatever keys it is handed, regardless of which cascade level they apply
 * to. The guard against a network admin writing a site-only key into the
 * network option therefore lives HERE, at the input boundary:
 * {@see sanitized_network_input()} builds its input array from exactly
 * {@see Schema::keys_for_level( Schema::LEVEL_NETWORK )} and nothing else,
 * so a key that is not network-applicable can never reach
 * {@see NetworkStore::save()} even if present in `$_POST` (a stray
 * `auto_archive_taxonomies[]` from a hand-crafted request, for instance —
 * `auto_archive_taxonomies` has no network level).
 *
 * @since 0.5.0
 */
final class NetworkSettingsPage implements HookableInterface {

	/** The admin page slug, per `add_submenu_page()`. */
	public const PAGE_SLUG = 'aps-network-settings';

	/** The nonce action {@see wp_nonce_field()} / {@see check_admin_referer()} share. */
	public const NONCE_ACTION = 'aps_network_settings';

	/** The `network_admin_edit_{$action}` action this screen's form posts to. */
	public const SAVE_ACTION = 'aps_save_network_settings';

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'network_admin_menu', array( $this, 'add_menu_page' ) ),
			HookDescriptor::action( 'network_admin_edit_' . self::SAVE_ACTION, array( $this, 'handle_save' ) ),
		);
	}

	/**
	 * `network_admin_menu` callback: adds the screen under Settings in the
	 * network admin, gated on the network settings capability. The menu is
	 * never added at all for a user who lacks it — not added-then-hidden.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical network-settings-capability accessor.
	 */
	public function add_menu_page(): void {
		if ( ! aps_current_user_can_manage_network_settings() ) {
			return;
		}

		add_submenu_page(
			'settings.php',
			__( 'Archived Post Status', 'archived-post-status' ),
			__( 'Archived Post Status', 'archived-post-status' ),
			NetworkSettingsCapability::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * `network_admin_edit_{$action}` callback: this screen's entire save
	 * path — see the class docblock for why it has to exist at all. Nonce
	 * first (dies on failure via core's own `check_admin_referer()`), then
	 * the capability (dies explicitly — nothing after this point is safe to
	 * reach without it), then sanitize-and-write, then redirect back to the
	 * screen with a status flag.
	 *
	 * `network_admin_edit.php` redirects to `network_admin_url()` itself
	 * once this action returns — reaching that fallback would silently drop
	 * the "saved" flag, so this method redirects and exits on its own,
	 * exactly like {@see \ArchivedPostStatus\Admin\PostActionHandler::handle_post_action()}.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical network-settings-capability/network-store accessors.
	 */
	public function handle_save(): void {
		check_admin_referer( self::NONCE_ACTION );

		if ( ! aps_current_user_can_manage_network_settings() ) {
			wp_die( esc_html__( 'You do not have permission to manage network settings.', 'archived-post-status' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
		NetworkStore::save( $this->sanitized_network_input( (array) wp_unslash( $_POST ) ) );

		wp_safe_redirect( add_query_arg( 'updated', 'true', network_admin_url( 'settings.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}

	/**
	 * Build a fully network-key-scoped, sanitized input array from raw POST
	 * data — see the class docblock's "Network-scoped sanitization" note.
	 * Every network-applicable key is included even when `$post_data` omits
	 * it entirely (an unchecked checkbox never appears in a POST body at
	 * all), so its sanitizer sees an explicit `null` rather than the key
	 * being silently skipped — that is what makes unchecking a checkbox
	 * actually persist as unchecked, instead of the missing key falling
	 * back to a stale stored `true` on the next {@see NetworkStore::save()}
	 * merge.
	 *
	 * @since 0.5.0
	 * @param array<string, mixed> $post_data Raw, unslashed $_POST.
	 * @return array<string, mixed> Sanitized, network-key-scoped values.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/sanitizer accessors.
	 */
	private function sanitized_network_input( array $post_data ): array {
		$input = array();

		foreach ( Schema::keys_for_level( Schema::LEVEL_NETWORK ) as $key ) {
			$input[ $key ] = $post_data[ $key ] ?? null;
		}

		return Sanitizer::sanitize( $input );
	}

	/**
	 * The page callback registered with `add_submenu_page()`.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical network-settings-capability accessor.
	 */
	public function render_page(): void {
		if ( ! aps_current_user_can_manage_network_settings() ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Archived Post Status', 'archived-post-status' ) . '</h1>';

		$this->maybe_render_updated_notice();

		echo '<form method="post" action="' . esc_url( network_admin_url( 'edit.php?action=' . self::SAVE_ACTION ) ) . '">';
		wp_nonce_field( self::NONCE_ACTION );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped HTML: render_fields() only ever concatenates CascadeField/SettingsRenderer output, both escaped at their own call sites (see their class docblocks).
		echo $this->render_fields();
		submit_button();
		echo '</form></div>';
	}

	/**
	 * A plain "saved" notice after {@see handle_save()}'s redirect lands
	 * back here with `?updated=true`. Read-only display flag, not a form
	 * submission — no nonce to verify.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	private function maybe_render_updated_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display flag, no state change.
		if ( empty( $_GET['updated'] ) ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>'
			. esc_html__( 'Network settings saved.', 'archived-post-status' )
			. '</p></div>';
	}

	/**
	 * Every network-level field: the one CascadeField (`auto_archive_days` +
	 * its `auto_archive_child_mode` downstream selector) plus every simple
	 * field, driven by {@see Schema::keys_for_level()} the same way
	 * {@see SettingsPage::render_fields()} is.
	 *
	 * @since 0.5.0
	 * @return string Escaped HTML — see {@see render_page()}'s echo site.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table accessor.
	 */
	private function render_fields(): string {
		$html = '';

		foreach ( Schema::keys_for_level( Schema::LEVEL_NETWORK ) as $key ) {
			if ( 'auto_archive_child_mode' === $key ) {
				continue; // Rendered as auto_archive_days's downstream selector, not its own row.
			}

			$html .= 'auto_archive_days' === $key ? $this->render_cascade_field() : $this->render_simple_field( $key );
		}

		return $html;
	}

	/**
	 * The network level is the top of the cascade: it inherits nothing
	 * ({@see CascadeInheritance::none()}) and always has the site level
	 * below it, so it always supplies a {@see DownstreamSelector}.
	 *
	 * @since 0.5.0
	 * @return string Escaped HTML.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/network-store/enum accessors.
	 */
	private function render_cascade_field(): string {
		$own_days   = NetworkStore::get( 'auto_archive_days', null );
		$child_mode = ChildMode::tryFrom( (string) NetworkStore::get( 'auto_archive_child_mode', ChildMode::Open->value ) )
			?? ChildMode::Open;

		return CascadeField::render(
			'auto_archive_days',
			Schema::label_for( 'auto_archive_days' ),
			Schema::description_for( 'auto_archive_days' ),
			CascadeInheritance::none(),
			$own_days,
			new DownstreamSelector(
				'auto_archive_child_mode',
				__( 'sites', 'archived-post-status' ),
				$child_mode
			)
		);
	}

	/**
	 * Every network-level field other than the cascade pair. The network
	 * level's key set (`scheduled_archive_enabled`, `auto_archive_enabled`,
	 * plus the cascade pair handled above) never includes anything but the
	 * checkbox shape, unlike the site screen's wider field-type match.
	 *
	 * @since 0.5.0
	 * @param string $key
	 * @return string Escaped HTML.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/network-store accessors.
	 */
	private function render_simple_field( string $key ): string {
		$value       = NetworkStore::get( $key, Schema::default_for( $key ) );
		$label       = Schema::label_for( $key );
		$description = Schema::description_for( $key );

		return match ( $key ) {
			'scheduled_archive_enabled', 'auto_archive_enabled' =>
				SettingsRenderer::checkbox( $key, $label, $description, (bool) $value ),
			default => '',
		};
	}
}
