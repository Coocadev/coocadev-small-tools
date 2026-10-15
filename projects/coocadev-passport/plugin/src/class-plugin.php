<?php
/**
 * Site-local plugin cards, native admin adapters and exact-value settings CAS.
 *
 * Notes are private administrative text, not plugin configuration or executable
 * instructions. Plugin basenames are selected from get_plugins(), never used as
 * arbitrary filesystem paths. Removed-plugin notes stay visible as retained data.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevPluginPassport
 */

namespace Coocadev\PluginPassport;

defined( 'ABSPATH' ) || exit;

/** Coordinates permission-checked operational cards without frontend hooks. */
final class Plugin {
	const OPTION = 'ccpp_data';

	/**
	 * Install one site's inactive data container without overwriting retained cards.
	 *
	 * @param bool $network_wide Whether network-wide activation was requested.
	 */
	public static function activate( bool $network_wide = false ): void {
		if ( $network_wide ) {
			wp_die( esc_html__( 'Activate Passport separately on each site.', 'coocadev-passport' ) );
		}
		add_option( self::OPTION, self::defaults(), '', false );
	}

	/** Register admin-only integrations; visitors never receive card assets or data. */
	public static function boot(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ) );
		add_filter( 'plugin_action_links', array( self::class, 'link' ), 10, 2 );
		add_action( 'admin_post_ccpp_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_ccpp_settings', array( self::class, 'handle_settings' ) );
		add_action( 'admin_post_ccpp_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_init', array( self::class, 'privacy' ) );
	}

	/** Require both configuration and plugin-management capabilities everywhere. */
	public static function allowed(): bool {
		return current_user_can( 'manage_options' ) && current_user_can( 'activate_plugins' );
	}

	/** Return the initial versioned container; no separate shared runtime is required. */
	public static function defaults(): array {
		return array(
			'schema'      => 1,
			'revision'    => 0,
			'remove_data' => false,
			'cards'       => array(),
		);
	}

	/** Read authoritative state; do not silently overwrite an unknown/corrupt schema. */
	public static function state(): array|\WP_Error {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact-value CAS requires the authoritative option, not a stale cache entry.
		$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		$data = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $data ) || 1 !== ( $data['schema'] ?? null ) || ! is_int( $data['revision'] ?? null ) || ! is_bool( $data['remove_data'] ?? null ) || ! is_array( $data['cards'] ?? null ) ) {
			return self::error( 'storage', __( 'The stored card schema is unavailable. Restore a known valid backup before saving.', 'coocadev-passport' ), 503 );
		}
		foreach ( $data['cards'] as $file => $fields ) {
			if ( ! is_string( $file ) || strlen( $file ) > 255 || is_wp_error( self::fields( $fields ) ) ) {
				return self::error( 'storage', __( 'A stored card is invalid. Restore a known valid backup before saving.', 'coocadev-passport' ), 503 );
			}
		}
		return $data;
	}

	/** Return installed plugin metadata without including any target plugin code. */
	public static function installed(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return get_plugins();
	}

	/**
	 * Recognize one exact installed basename; traversal/URLs/unknown files are denied.
	 *
	 * @param mixed $basename Exact installed plugin basename, never a filesystem path.
	 */
	public static function target( mixed $basename ): bool {
		return is_string( $basename ) && strlen( $basename ) <= 255 && isset( self::installed()[ $basename ] );
	}

	/**
	 * Build a public-safe typed error; never include database internals or private notes.
	 *
	 * @param string $code Stable plugin-specific error suffix.
	 * @param string $message Translated public-safe error text.
	 * @param int    $status Intended HTTP response status.
	 */
	private static function error( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'ccpp_' . $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Validate plain text before mutation, including arrays, UTF-8 and byte limits.
	 *
	 * @param mixed $input Untrusted card fields to validate and sanitize.
	 */
	public static function fields( mixed $input ): array|\WP_Error {
		$limits = array(
			'purpose'     => 1000,
			'usage'       => 4000,
			'responsible' => 200,
			'checklist'   => 4000,
		);
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( $limits ) ) || count( $input ) !== count( $limits ) ) {
			return self::error( 'fields', __( 'Send exactly the four card text fields.', 'coocadev-passport' ) );
		}
		$result = array();
		foreach ( $limits as $key => $limit ) {
			if ( ! is_string( $input[ $key ] ?? null ) || strlen( $input[ $key ] ) > $limit || ! preg_match( '//u', $input[ $key ] ) || str_contains( $input[ $key ], "\0" ) ) {
				return self::error( 'fields', __( 'A card field is invalid or too long.', 'coocadev-passport' ) );
			}
			$result[ $key ] = sanitize_textarea_field( $input[ $key ] );
		}
		return $result;
	}

	/**
	 * Save an installed plugin's card without granting authority from its basename.
	 *
	 * @param mixed $basename Exact installed plugin basename, never a filesystem path.
	 * @param mixed $input Untrusted card fields to validate and sanitize.
	 * @param int   $revision Expected site data revision from the form.
	 */
	public static function save( mixed $basename, mixed $input, int $revision ): array|\WP_Error {
		if ( ! self::allowed() ) {
			return self::error( 'forbidden', __( 'You cannot manage plugin cards.', 'coocadev-passport' ), 403 );
		}
		if ( ! self::target( $basename ) ) {
			return self::error( 'target', __( 'Select an installed plugin from the Plugins screen.', 'coocadev-passport' ) );
		}
		$fields = self::fields( $input );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$data = self::state();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		// A single bounded option keeps this small tool simple; fail rather than grow without limit.
		if ( ! isset( $data['cards'][ $basename ] ) && count( $data['cards'] ) >= 1000 ) {
			return self::error( 'limit', __( 'This site has reached the 1,000 retained-card safety limit.', 'coocadev-passport' ) );
		}
		$data['cards'][ $basename ] = $fields;
		return self::replace( $data, $revision );
	}

	/**
	 * Change retention only after an explicit authorized settings action.
	 *
	 * @param bool $remove Explicit uninstall-removal preference.
	 * @param int  $revision Expected site data revision from the form.
	 */
	public static function retention( bool $remove, int $revision ): array|\WP_Error {
		if ( ! self::allowed() ) {
			return self::error( 'forbidden', __( 'You cannot change card retention.', 'coocadev-passport' ), 403 );
		}
		$data = self::state();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['remove_data'] = $remove;
		return self::replace( $data, $revision );
	}

	/**
	 * Compare exact old bytes so concurrent requests cannot both replace a revision.
	 *
	 * @param array $next Complete next state derived from the current revision.
	 * @param int   $revision Expected site data revision from the form.
	 */
	private static function replace( array $next, int $revision ): array|\WP_Error {
		global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WordPress has no option CAS; the exact prior value guards the write.
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, self::OPTION ) );
		$old = is_string( $raw ) ? maybe_unserialize( $raw ) : null;
		if ( ! is_array( $old ) || ( $old['revision'] ?? null ) !== $revision || ( $next['revision'] ?? null ) !== $revision ) {
			return self::error( 'conflict', __( 'A card changed in another tab. Reload before saving.', 'coocadev-passport' ), 409 );
		}
		$next['revision'] = $revision + 1;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Atomic conditional replacement of this plugin's one option; cache invalidated immediately below.
		$changed = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, maybe_serialize( $next ), self::OPTION, $raw ) );
		if ( 1 !== $changed ) {
			return self::error( 'conflict', __( 'The card could not be saved. Reload and retry.', 'coocadev-passport' ), 409 );
		}
		wp_cache_delete( self::OPTION, 'options' );
		return $next;
	}

	/** Build a private download payload only after repeating the administrative check. */
	public static function export(): array|\WP_Error {
		if ( ! self::allowed() ) {
			return self::error( 'forbidden', __( 'You cannot export plugin cards.', 'coocadev-passport' ), 403 );
		}
		$data = self::state();
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$installed = self::installed();
		$cards     = array();
		foreach ( $data['cards'] as $file => $fields ) {
			$cards[] = array(
				'plugin'    => $file,
				'installed' => isset( $installed[ $file ] ),
				'card'      => $fields,
			);
		}
		return array(
			'format'   => 'coocadev-passport',
			'schema'   => 1,
			'revision' => $data['revision'],
			'cards'    => $cards,
		);
	}

	/**
	 * Add a contextual link only for authorized administrators on native Plugins lists.
	 *
	 * @param array  $links Existing native plugin action links.
	 * @param string $file Installed plugin basename or an empty overview selection.
	 */
	public static function link( array $links, string $file ): array {
		if ( self::allowed() && self::target( $file ) ) {
			$links['ccpp_card'] = '<a href="' . esc_url( self::url( $file ) ) . '">' . esc_html__( 'Passport', 'coocadev-passport' ) . '</a>';
		}
		return $links;
	}

	/** Register the native settings page; render() repeats both capabilities. */
	public static function menu(): void {
		add_options_page( __( 'Passport', 'coocadev-passport' ), __( 'Passport', 'coocadev-passport' ), 'manage_options', 'ccpp-cards', array( self::class, 'render' ) );
	}

	/**
	 * Use WordPress-generated URLs; a plugin basename is a query value, never a path.
	 *
	 * @param string $file Installed plugin basename or an empty overview selection.
	 */
	private static function url( string $file = '' ): string {
		return add_query_arg(
			array(
				'page'   => 'ccpp-cards',
				'plugin' => $file,
			),
			admin_url( 'options-general.php' )
		);
	}

	/** Check request method, both capabilities and action nonce before reading user fields. */
	private static function guard(): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! self::allowed() ) {
			wp_die( esc_html__( 'This action requires an authorized POST request.', 'coocadev-passport' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Parse a canonical non-negative revision without coercing arrays or malformed numbers.
	 *
	 * @param mixed $value Untrusted candidate value; no coercion is allowed.
	 */
	private static function posted_revision( mixed $value ): int {
		if ( ! is_string( $value ) || ! preg_match( '/^(?:0|[1-9][0-9]{0,9})$/D', $value ) ) {
			wp_die( esc_html__( 'The card revision is invalid.', 'coocadev-passport' ), '', array( 'response' => 400 ) );
		}
		return (int) $value;
	}

	/**
	 * Return a truthful error or redirect only after confirmed persistence.
	 *
	 * @param array|\WP_Error $result Confirmed stored state or a public-safe error.
	 * @param string          $file Installed plugin basename or an empty overview selection.
	 */
	private static function complete( array|\WP_Error $result, string $file = '' ): never {
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => absint( $result->get_error_data()['status'] ?? 400 ) ) );
		}
		wp_safe_redirect( add_query_arg( 'ccpp_saved', '1', self::url( $file ) ) );
		exit;
	}

	/** Normalize only slashed form fields; no raw request array reaches the repository. */
	public static function handle_save(): void {
		self::guard();
		check_admin_referer( 'ccpp_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact installed-basename allowlist is checked in save(); sanitizing a different path into an installed name would be wrong.
		$file   = isset( $_POST['plugin'] ) && is_string( $_POST['plugin'] ) ? wp_unslash( $_POST['plugin'] ) : null;
		$fields = array();
		foreach ( array( 'purpose', 'usage', 'responsible', 'checklist' ) as $key ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- fields() first validates UTF-8/type/byte limits, then sanitizes the allowed text fields.
			$fields[ $key ] = isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- posted_revision() strictly rejects noncanonical numbers and non-string values.
		self::complete( self::save( $file, $fields, self::posted_revision( wp_unslash( $_POST['revision'] ?? null ) ) ), is_string( $file ) ? $file : '' );
	}

	/** Present malformed checkbox input is an error, not an instruction to turn it off. */
	public static function handle_settings(): void {
		self::guard();
		check_admin_referer( 'ccpp_settings' );
		if ( isset( $_POST['remove_data'] ) && '1' !== $_POST['remove_data'] ) {
			wp_die( esc_html__( 'The retention checkbox is invalid.', 'coocadev-passport' ), '', array( 'response' => 400 ) );
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Canonical typed revision validator rejects malformed values before any write.
		self::complete( self::retention( isset( $_POST['remove_data'] ), self::posted_revision( wp_unslash( $_POST['revision'] ?? null ) ) ) );
	}

	/** Send an explicit JSON download without sharing cards with page caches. */
	public static function handle_export(): void {
		self::guard();
		check_admin_referer( 'ccpp_export' );
		$data = self::export();
		if ( is_wp_error( $data ) ) {
			self::complete( $data );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Content-Disposition: attachment; filename="plugin-passports.json"' );
		wp_send_json( $data );
	}

	/** Native WordPress form; no frontend route, script or remote service is needed. */
	public static function render(): void {
		if ( ! self::allowed() ) {
			wp_die( esc_html__( 'You cannot manage plugin cards.', 'coocadev-passport' ), '', array( 'response' => 403 ) );
		}
		nocache_headers();
		$data = self::state();
		if ( is_wp_error( $data ) ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Passport', 'coocadev-passport' ) . '</h1><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
			return;
		}
		$plugins = self::installed();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only card selection; both capabilities were checked and target() requires an exact installed basename.
		$file = isset( $_GET['plugin'] ) && is_string( $_GET['plugin'] ) ? wp_unslash( $_GET['plugin'] ) : '';
		echo '<div class="wrap"><h1>' . esc_html__( 'Passport', 'coocadev-passport' ) . '</h1><p>' . esc_html__( 'Private operational notes. Do not store passwords, API keys or customer data. Exports contain your note text.', 'coocadev-passport' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Cosmetic read-only success notice; no mutation or user text is accepted.
		if ( isset( $_GET['ccpp_saved'] ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Saved.', 'coocadev-passport' ) . '</p></div>';
		}
		if ( '' !== $file ) {
			if ( ! self::target( $file ) ) {
				echo '<p>' . esc_html__( 'This plugin is not installed. Retained cards are listed below and cannot be edited until the same basename is installed again.', 'coocadev-passport' ) . '</p>';
			} else {
				$fields = $data['cards'][ $file ] ?? array_fill_keys( array( 'purpose', 'usage', 'responsible', 'checklist' ), '' );
				echo '<h2>' . esc_html( $plugins[ $file ]['Name'] ) . '</h2><p><code>' . esc_html( $file ) . '</code> · ' . esc_html( is_plugin_active( $file ) ? __( 'Active', 'coocadev-passport' ) : __( 'Installed, inactive', 'coocadev-passport' ) ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'ccpp_save' );
				echo '<input type="hidden" name="action" value="ccpp_save"><input type="hidden" name="plugin" value="' . esc_attr( $file ) . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $data['revision'] ) . '">';
				$labels = array(
					'purpose'     => __( 'Purpose', 'coocadev-passport' ),
					'usage'       => __( 'Where and how it is used', 'coocadev-passport' ),
					'responsible' => __( 'Responsible person or team', 'coocadev-passport' ),
					'checklist'   => __( 'Update checklist', 'coocadev-passport' ),
				);
				foreach ( $labels as $key => $label ) {
					echo '<p><label for="ccpp-' . esc_attr( $key ) . '"><strong>' . esc_html( $label ) . '</strong></label><br><textarea class="large-text" rows="4" id="ccpp-' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . esc_textarea( $fields[ $key ] ) . '</textarea></p>';
				}
				submit_button( __( 'Save passport', 'coocadev-passport' ) );
				echo '</form>';
			}
		}
		echo '<h2>' . esc_html__( 'Installed plugins', 'coocadev-passport' ) . '</h2><ul>';
		foreach ( $plugins as $basename => $details ) {
			echo '<li><a href="' . esc_url( self::url( $basename ) ) . '">' . esc_html( $details['Name'] ) . '</a></li>';
		}
		echo '</ul><h2>' . esc_html__( 'Retained cards for removed plugins', 'coocadev-passport' ) . '</h2>';
		foreach ( $data['cards'] as $basename => $fields ) {
			if ( ! isset( $plugins[ $basename ] ) ) {
				echo '<details><summary>' . esc_html( $basename ) . '</summary>';
				foreach ( $fields as $key => $value ) {
					echo '<p><strong>' . esc_html( $key ) . '</strong></p><pre>' . esc_html( $value ) . '</pre>';
				}
				echo '</details>';
			}
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccpp_export">';
		wp_nonce_field( 'ccpp_export' );
		submit_button( __( 'Export cards as JSON', 'coocadev-passport' ), 'secondary' );
		echo '</form><hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccpp_settings"><input type="hidden" name="revision" value="' . esc_attr( (string) $data['revision'] ) . '">';
		wp_nonce_field( 'ccpp_settings' );
		echo '<label><input type="checkbox" name="remove_data" value="1" ' . checked( $data['remove_data'], true, false ) . '> ' . esc_html__( 'Delete all Passport cards and settings when this plugin is uninstalled.', 'coocadev-passport' ) . '</label>';
		submit_button( __( 'Save retention preference', 'coocadev-passport' ), 'secondary' );
		echo '</form></div>';
	}

	/** Explain free-form personal-data risk without copying account emails or telemetry. */
	public static function privacy(): void {
		wp_add_privacy_policy_content( 'Coocadev Passport', '<p>' . esc_html__( 'Passport stores private operational cards about installed plugins in this site’s database. Free-form responsible-person names and notes may contain personal information entered by administrators. Cards and explicit JSON exports are available only to users with both manage_options and activate_plugins. No data is sent to Coocadev. Review and remove personal text manually when handling a privacy request; this plugin does not infer account ownership from free text. Data is retained on deactivation and by default on uninstall, unless the administrator opts into removing the plugin’s own data.', 'coocadev-passport' ) . '</p>' );
	}
}
