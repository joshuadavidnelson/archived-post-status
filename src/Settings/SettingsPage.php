<?php

namespace ArchivedPostStatus\Settings;

// Exit if accessed directly, prevent direct access to this file.
if ( ! defined( 'ABSPATH' ) ) { die; } // phpcs:ignore

use ArchivedPostStatus\AutoArchive\ChildMode;
use ArchivedPostStatus\AutoArchive\MatchCountPreview;
use ArchivedPostStatus\Contracts\HookableInterface;
use ArchivedPostStatus\Hooks\HookDescriptor;
use ArchivedPostStatus\Schedule\Queue\QueueTelemetry;

/**
 * The site settings screen: `Settings → Archived Post Status`.
 *
 * Registers under Settings (`add_options_page()`) and wires the option into
 * the Settings API (`register_setting()`), which is the entire REST
 * requirement for site settings (§5.8) — core exposes `aps_settings` at
 * `GET`/`POST /wp/v2/settings` once `show_in_rest` is attached, gated on
 * `manage_options` by core itself, independent of this plugin's own
 * filterable capability. The form's nonce comes from `settings_fields()`,
 * not a hand-rolled one; the capability gate sits on both the menu item
 * (`add_options_page()`'s own capability argument, checked again defensively
 * in {@see render_page()}) and the form's own POST handler in `options.php`
 * (the `option_page_capability_aps` filter this class also registers).
 *
 * Deliberately does not use `add_settings_section()`/`add_settings_field()`
 * — every field this screen renders already comes back as a complete,
 * escaped fieldset from {@see CascadeField} or {@see SettingsRenderer}, so
 * routing it through WordPress's section/field registry would only add
 * indirection with no benefit; that machinery exists for third parties to
 * inject fields into a screen they do not own, which does not apply here.
 *
 * @since 0.5.0
 */
final class SettingsPage implements HookableInterface {

	/** The Settings API option group this screen registers under. */
	public const OPTION_GROUP = 'aps';

	/** The admin page slug, per `add_options_page()`. */
	public const PAGE_SLUG = 'aps-settings';

	/**
	 * The match-count preview (plan risk #2's mitigation): how many posts
	 * currently match the configured auto-archive rule.
	 *
	 * @since 0.5.0
	 * @var MatchCountPreview
	 */
	private readonly MatchCountPreview $match_count_preview;

	/**
	 * Constructor.
	 *
	 * @since 0.5.0
	 * @param ?MatchCountPreview $match_count_preview Defaults to a real one.
	 */
	public function __construct( ?MatchCountPreview $match_count_preview = null ) {
		$this->match_count_preview = $match_count_preview ?? new MatchCountPreview();
	}

	/**
	 * @since 0.5.0
	 * @return HookDescriptor[]
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- HookDescriptor named constructors.
	 */
	public function hooks(): array {
		return array(
			HookDescriptor::action( 'admin_menu', array( $this, 'add_menu_page' ) ),
			HookDescriptor::action( 'admin_init', array( $this, 'register_setting' ) ),
			HookDescriptor::filter(
				'option_page_capability_' . self::OPTION_GROUP,
				array( $this, 'option_page_capability' )
			),
		);
	}

	/**
	 * `admin_menu` callback: adds the screen under Settings, gated on the
	 * settings capability. The menu is never added at all for a user who
	 * lacks it — not added-then-hidden.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-capability accessor.
	 */
	public function add_menu_page(): void {
		if ( ! aps_current_user_can_manage_settings() ) {
			return;
		}

		add_options_page(
			__( 'Archived Post Status', 'archived-post-status' ),
			__( 'Archived Post Status', 'archived-post-status' ),
			SettingsCapability::capability(),
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * `admin_init` callback: registers `aps_settings` with the Settings API.
	 * `show_in_rest` is the entire REST requirement — see the class
	 * docblock.
	 *
	 * Unconditional, unlike {@see add_menu_page()}: the Settings API expects
	 * every option group to register on every admin request regardless of
	 * the current user, the same way core's own `register_setting()` calls
	 * do; the form's actual write path is separately gated by
	 * {@see option_page_capability()}.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table accessors.
	 */
	public function register_setting(): void {
		register_setting(
			self::OPTION_GROUP,
			Store::OPTION_KEY,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( Sanitizer::class, 'sanitize' ),
				'default'           => Store::defaults(),
				'show_in_rest'      => array( 'schema' => RestSchema::site() ),
			)
		);
	}

	/**
	 * `option_page_capability_aps` filter callback: the capability
	 * `options.php` requires before accepting a POST for this settings
	 * group. Without this, `options.php` would keep enforcing its own
	 * `manage_options` default regardless of a site overriding
	 * `aps_default_settings_capability` away from it — the menu item would
	 * use the custom capability while the form submission that reaches it
	 * still required a different one.
	 *
	 * @since 0.5.0
	 * @param string $capability The capability options.php would otherwise require.
	 * @return string
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-capability accessor.
	 */
	public function option_page_capability( string $capability ): string {
		unset( $capability );

		return SettingsCapability::capability();
	}

	/**
	 * The page callback registered with `add_options_page()`.
	 *
	 * @since 0.5.0
	 * @return void
	 */
	public function render_page(): void {
		if ( ! aps_current_user_can_manage_settings() ) {
			return;
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Archived Post Status', 'archived-post-status' ) . '</h1>';

		$this->maybe_render_cron_health_notice();

		echo '<form method="post" action="options.php">';
		settings_fields( self::OPTION_GROUP );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already-escaped HTML: render_fields() only ever concatenates CascadeField/SettingsRenderer output, both escaped at their own call sites (see their class docblocks).
		echo $this->render_fields();
		$this->maybe_render_match_count_preview();
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Every non-cascade field plus the one combined cascade fieldset
	 * (`auto_archive_days` + its `auto_archive_child_mode` downstream
	 * selector), driven by {@see Schema::keys_for_level()} so a schema
	 * addition needs no matching edit here beyond {@see render_simple_field()}.
	 *
	 * @since 0.5.0
	 * @return string Escaped HTML — see {@see render_page()}'s echo site.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table accessor.
	 */
	private function render_fields(): string {
		$html = '';

		foreach ( Schema::keys_for_level( Schema::LEVEL_SITE ) as $key ) {
			if ( 'auto_archive_child_mode' === $key ) {
				continue; // Rendered as auto_archive_days's downstream selector, not its own row.
			}

			$html .= 'auto_archive_days' === $key ? $this->render_cascade_field() : $this->render_simple_field( $key );
		}

		return $html;
	}

	/**
	 * The one CascadeField this screen renders: `auto_archive_days`, with
	 * `auto_archive_child_mode` folded in as its downstream selector. The
	 * site level has no ancestor yet — {@see NetworkRuleProvider} lands in
	 * phase 7 — so today this is always the top of the chain from this
	 * screen's point of view.
	 *
	 * @since 0.5.0
	 * @return string Escaped HTML.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/store accessors.
	 */
	private function render_cascade_field(): string {
		$own_days   = Store::get( 'auto_archive_days', null );
		$child_mode = ChildMode::tryFrom( (string) Store::get( 'auto_archive_child_mode', ChildMode::Open->value ) )
			?? ChildMode::Open;

		return CascadeField::render(
			'auto_archive_days',
			Schema::label_for( 'auto_archive_days' ),
			Schema::description_for( 'auto_archive_days' ),
			CascadeInheritance::none(),
			$own_days,
			new DownstreamSelector(
				'auto_archive_child_mode',
				__( 'categories and posts', 'archived-post-status' ),
				$child_mode
			)
		);
	}

	/**
	 * Every site-level field other than the cascade pair, dispatched by key
	 * to the matching {@see SettingsRenderer} field type.
	 *
	 * @since 0.5.0
	 * @param string $key
	 * @return string Escaped HTML.
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-table/store accessors.
	 */
	private function render_simple_field( string $key ): string {
		$value       = Store::get( $key, Schema::default_for( $key ) );
		$label       = Schema::label_for( $key );
		$description = Schema::description_for( $key );

		return match ( $key ) {
			'is_read_only', 'scheduled_archive_enabled', 'auto_archive_enabled' =>
				SettingsRenderer::checkbox( $key, $label, $description, (bool) $value ),
			'auto_archive_grace_days' =>
				SettingsRenderer::number( $key, $label, $description, (int) $value ),
			'auto_archive_age_basis' =>
				SettingsRenderer::select( $key, $label, $description, self::age_basis_choices(), (string) $value ),
			'scheduled_archive_post_types', 'auto_archive_types' =>
				SettingsRenderer::multi_checkbox( $key, $label, $description, $this->post_type_choices(), (array) $value ),
			'auto_archive_taxonomies' =>
				SettingsRenderer::multi_checkbox( $key, $label, $description, $this->taxonomy_choices(), (array) $value ),
			default => '',
		};
	}

	/**
	 * @since 0.5.0
	 * @return array<string, string> Value => display label.
	 */
	private static function age_basis_choices(): array {
		return array(
			'modified'  => __( 'Last modified', 'archived-post-status' ),
			'published' => __( 'Published', 'archived-post-status' ),
		);
	}

	/**
	 * @since 0.5.0
	 * @return array<string, string> Slug => display label.
	 */
	private function post_type_choices(): array {
		$choices = array();

		foreach ( aps_get_supported_post_types() as $post_type ) {
			$object                = get_post_type_object( $post_type );
			$choices[ $post_type ] = $object ? $object->labels->name : $post_type;
		}

		return $choices;
	}

	/**
	 * @since 0.5.0
	 * @return array<string, string> Slug => display label.
	 */
	private function taxonomy_choices(): array {
		$choices = array();

		// Keyed by $object->name rather than the loop's own array key: the
		// WordPress stubs declare get_taxonomies()'s return type as
		// array<int, WP_Taxonomy> regardless of $output, so the object's own
		// property -- not the array key -- is the reliable taxonomy slug.
		foreach ( get_taxonomies( array(), 'objects' ) as $object ) {
			$choices[ $object->name ] = (string) ( $object->labels->name ?? $object->name );
		}

		return $choices;
	}

	/**
	 * The match-count preview (plan risk #2): how many posts currently
	 * match the *stored* auto-archive rule, shown before it can ever fire.
	 * Silent when auto-archive is off, or on but with no `auto_archive_days`
	 * value stored yet — there is nothing to preview in either case.
	 *
	 * Reads the stored values directly through {@see Store::get()}, the
	 * same way {@see render_cascade_field()} does, rather than through the
	 * `aps_*` filters {@see MatchCountPreview}'s own query builder resolves
	 * against — this is a preview of what is saved, not of a filtered
	 * runtime value a developer override might diverge from.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical settings-store/schema accessors.
	 */
	private function maybe_render_match_count_preview(): void {
		if ( ! (bool) Store::get( 'auto_archive_enabled', Schema::default_for( 'auto_archive_enabled' ) ) ) {
			return;
		}

		$days = Store::get( 'auto_archive_days', Schema::default_for( 'auto_archive_days' ) );

		if ( null === $days ) {
			return;
		}

		$count = $this->match_count_preview->count( (int) $days, time() );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: number of posts, already formatted for the current locale. */
				_n(
					'%s post currently matches this rule and will be scheduled to archive.',
					'%s posts currently match this rule and will be scheduled to archive.',
					$count,
					'archived-post-status'
				),
				number_format_i18n( $count )
			)
		) . '</p>';
	}

	/**
	 * The screen's cron health notice (§5.4): a plain admin notice, only on
	 * this screen, warning when the sweep has gone stale or has never run
	 * while `DISABLE_WP_CRON` is in effect.
	 *
	 * @since 0.5.0
	 * @return void
	 *
	 * @SuppressWarnings("PHPMD.StaticAccess") -- canonical cron-staleness judgment.
	 */
	private function maybe_render_cron_health_notice(): void {
		$last_sweep      = QueueTelemetry::last_run( 'sweep' );
		$disable_wp_cron = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$interval        = (int) apply_filters( 'aps_schedule_sweep_interval', 300 );

		/**
		 * How many sweep intervals of silence before the cron health notice
		 * warns (§5.4, §5.11) — every other magic number this feature
		 * compares against is filterable, and a site with an unusual cron
		 * cadence has the same reason to tune this one.
		 *
		 * @since 0.5.0
		 * @param int $multiplier Default {@see CronHealthCheck::DEFAULT_STALE_MULTIPLIER}.
		 */
		$multiplier = (int) apply_filters( 'aps_schedule_stale_multiplier', CronHealthCheck::DEFAULT_STALE_MULTIPLIER );

		if ( ! CronHealthCheck::is_stale( $last_sweep, $disable_wp_cron, time(), $interval, $multiplier ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>' . esc_html__(
			'The archive sweep has not run recently. If your host disables real WP-Cron, make sure a system cron job hits wp-cron.php on a schedule.',
			'archived-post-status'
		) . '</p></div>';
	}
}
