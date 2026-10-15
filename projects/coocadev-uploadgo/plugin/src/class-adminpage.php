<?php
/**
 * Native settings and narrowly enqueued media guidance.
 *
 * Administrative request adapter and explanatory media-screen hints.
 * The visible form is not the write boundary: each action repeats POST,
 * capability and nonce checks before parsing typed fields and revision.
 * Client hints are optional guidance; the server inspector is decisive.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

namespace Coocadev\UploadGo;

defined( 'ABSPATH' ) || exit;

/**
 * Administrator controls and effective-policy guidance.
 */
final class AdminPage {
	/**
	 * Allowlisted form fields captured only after capability and nonce verification.
	 *
	 * @var array
	 */
	private static array $form = array();

	/**
	 * Connect native settings, guarded handlers and editor guidance.
	 *
	 * @return void Operation result.
	 */
	public static function register(): void {
		add_action(
			'admin_menu',
			static function () {
				add_options_page( __( 'UploadGo', 'coocadev-uploadgo' ), __( 'UploadGo', 'coocadev-uploadgo' ), 'manage_options', 'ccug-settings', array( self::class, 'render' ) );
			}
		);
		add_action(
			'admin_init',
			static function () {
				register_setting(
					'ccug',
					'ccug_settings',
					array(
						'type'         => 'array',
						'show_in_rest' => false,
					)
				);
			}
		);
		// Writes must go through our revision-aware handler, never options.php.
		add_filter( 'option_page_capability_ccug', static fn() => 'do_not_allow' );
		add_action( 'admin_post_ccug_save', array( self::class, 'save' ) );
		add_action( 'admin_post_ccug_export', array( self::class, 'export' ) );
		add_action( 'admin_post_ccug_reset', array( self::class, 'reset' ) );
		add_action( 'admin_post_ccug_clear', array( self::class, 'clear' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'pre-upload-ui', array( self::class, 'hint' ) );
		add_filter( 'plupload_init', array( self::class, 'plupload' ) );
		add_action(
			'admin_init',
			static function () {
				if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
					wp_add_privacy_policy_content( 'Coocadev UploadGo', __( 'UploadGo checks file size, type and image dimensions locally during supported WordPress uploads. It does not send files or usage data to Coocadev. Optional diagnostics store at most 100 reason codes, upload channels and timestamps. The screen shows the last 24 hours. Expired records are removed when WordPress scheduled cleanup next runs, on the next recorded event, on deactivation, or with Clear diagnostics; cleanup may be delayed on an idle site.', 'coocadev-uploadgo' ) );
				}
			}
		);
	}

	/**
	 * Authenticate a POST and capture only validated allowlisted controls.
	 *
	 * @param string $action Action.
	 * @return void Operation result.
	 */
	private static function guard( string $action ): void {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) && is_string( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method || ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot change upload policies.', 'coocadev-uploadgo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
		self::$form = array();
		foreach ( array( 'enabled', 'background_mode', 'diagnostics_enabled', 'remove_data_on_uninstall', 'confirm_empty', 'confirm' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && ( ! is_string( $_POST[ $key ] ) || '1' !== sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) || '1' !== wp_unslash( $_POST[ $key ] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact literal comparison rejects malformed checkbox tokens; sanitized comparison alone could accept altered input.
				self::finish( new \WP_Error( 'ccug_invalid', __( 'Invalid checkbox value. Reload the form and try again.', 'coocadev-uploadgo' ) ) );
			}
			self::$form[ $key ] = isset( $_POST[ $key ] );
		}
		$revision               = isset( $_POST['revision'] ) && is_string( $_POST['revision'] ) ? sanitize_text_field( wp_unslash( $_POST['revision'] ) ) : '';
		self::$form['revision'] = ctype_digit( $revision ) && strlen( $revision ) <= 18 ? (int) $revision : 0;
		// Only this documented subtree is read. form_policy strictly rejects noncanonical numbers, unknown keys and invalid family tokens.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Typed allowlist validation preserves malformed tokens so they are rejected rather than silently repaired.
		self::$form['policy'] = isset( $_POST['policy'] ) && is_array( $_POST['policy'] ) ? wp_unslash( $_POST['policy'] ) : array();
	}

	/**
	 * Return the revision captured by the verified request guard.
	 *
	 * @return int Operation result.
	 */
	private static function revision(): int {
		return self::$form['revision'] ?? 0;
	}

	/**
	 * Read a checkbox token already validated by the request guard.
	 *
	 * @param string $key Key.
	 * @return bool Operation result.
	 */
	private static function checked( string $key ): bool {
		return true === ( self::$form[ $key ] ?? false );
	}

	/**
	 * Validate a canonical form policy without silently dropping malformed fields.
	 *
	 * @param mixed $input Input.
	 * @return array|\WP_Error Operation result.
	 */
	private static function form_policy( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( PolicyRepository::empty_policy() ) ) ) {
			return new \WP_Error( 'ccug_invalid', __( 'Invalid policy form.', 'coocadev-uploadgo' ) );
		}
		$result = PolicyRepository::empty_policy();
		foreach ( array( 'max_bytes', 'max_width', 'max_height', 'max_pixels' ) as $key ) {
			$value = $input[ $key ] ?? '';
			if ( ! is_string( $value ) || ( '' !== $value && ! ctype_digit( $value ) ) ) {
				return new \WP_Error( 'ccug_invalid', __( 'Use whole numbers for upload limits.', 'coocadev-uploadgo' ) );
			}
			$result[ $key ] = '' === $value ? null : (int) $value;
		}
		if ( ( isset( $input['types_enabled'] ) && '1' !== $input['types_enabled'] ) || ( isset( $input['types'] ) && ! is_array( $input['types'] ) ) ) {
			return new \WP_Error( 'ccug_invalid', __( 'Invalid file type controls.', 'coocadev-uploadgo' ) );
		}
		$result['types_enabled'] = isset( $input['types_enabled'] );
		$result['types']         = $input['types'] ?? array();
		return PolicyRepository::validate_policy( $result );
	}

	/**
	 * Validate the complete policy form and perform a revision-aware save.
	 *
	 * @return void Operation result.
	 */
	public static function save(): void {
		self::guard( 'ccug_save' );
		$settings                    = PolicyRepository::defaults();
		$settings['config_revision'] = self::revision();
		foreach ( array( 'enabled', 'background_mode', 'diagnostics_enabled', 'remove_data_on_uninstall' ) as $key ) {
			$settings[ $key ] = self::checked( $key );
		}
		$input = self::$form['policy'];
		if ( array_diff( array_keys( $input ), array_merge( array( 'global' ), array_keys( wp_roles()->roles ) ) ) ) {
			self::finish( new \WP_Error( 'ccug_invalid', __( 'Unknown role policy.', 'coocadev-uploadgo' ) ) );
		}
		$global = self::form_policy( $input['global'] ?? null );
		if ( is_wp_error( $global ) ) {
			self::finish( $global );
		}
		$settings['global_policy'] = $global;
		foreach ( wp_roles()->roles as $role => $details ) {
			$policy = self::form_policy( $input[ $role ] ?? null );
			if ( is_wp_error( $policy ) ) {
				self::finish( $policy );
			}
			$settings['role_policies'][ $role ] = $policy;
		}
		foreach ( array_merge( array( $global ), array_values( $settings['role_policies'] ) ) as $policy ) {
			if ( $policy['types_enabled'] && ! $policy['types'] && ! self::checked( 'confirm_empty' ) ) {
				self::finish( new \WP_Error( 'ccug_empty_types', __( 'Confirm that an enabled empty type list will block every new upload for that policy.', 'coocadev-uploadgo' ) ) );
			}
		}
		self::finish( PolicyRepository::save( $settings, self::revision() ) );
	}

	/**
	 * Render a safe settings error or redirect after a confirmed operation.
	 *
	 * @param mixed $result Result.
	 * @return void Operation result.
	 */
	private static function finish( $result ): void {
		if ( is_wp_error( $result ) ) {
			wp_die(
				esc_html( $result->get_error_message() ),
				esc_html__( 'UploadGo settings', 'coocadev-uploadgo' ),
				array(
					'response'  => 'ccug_conflict' === $result->get_error_code() ? 409 : 400,
					'back_link' => true,
				)
			);
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => 'ccug-settings',
					'saved' => 1,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Reset only UploadGo settings after explicit confirmation and revision checking.
	 *
	 * @return void Operation result.
	 */
	public static function reset(): void {
		self::guard( 'ccug_reset' );
		if ( ! self::checked( 'confirm' ) ) {
			self::finish( new \WP_Error( 'ccug_confirm', __( 'Confirm the reset first.', 'coocadev-uploadgo' ) ) );
		}
		self::finish( PolicyRepository::save( PolicyRepository::defaults(), self::revision() ) );
	}

	/**
	 * Clear only local diagnostics after administrator and nonce checks.
	 *
	 * @return void Operation result.
	 */
	public static function clear(): void {
		self::guard( 'ccug_clear' );
		delete_option( 'ccug_diagnostics' );
		self::finish( true );
	}

	/**
	 * Download a JSON policy after administrator and nonce checks.
	 *
	 * @return void Operation result.
	 */
	public static function export(): void {
		self::guard( 'ccug_export' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="uploadgo-policy.json"' );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download with fixed content type, no HTML.
		echo wp_json_encode( PolicyRepository::read(), JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Load local assets only on the relevant settings or media screens.
	 *
	 * @param string $hook Hook.
	 * @return void Operation result.
	 */
	public static function enqueue( string $hook ): void {
		$screen = get_current_screen();
		if ( 'settings_page_ccug-settings' === $hook ) {
			wp_enqueue_style( 'ccug-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		}
		if ( $screen && in_array( $screen->base, array( 'post', 'upload', 'media' ), true ) && current_user_can( 'upload_files' ) ) {
			wp_enqueue_script( 'ccug-media', plugins_url( 'assets/media.js', FILE ), array( 'wp-i18n', 'plupload-all' ), VERSION, true );
			wp_set_script_translations( 'ccug-media', 'coocadev-uploadgo' );
			wp_localize_script(
				'ccug-media',
				'ccugMedia',
				array(
					'policy'   => PolicyResolver::current(),
					'maxBytes' => wp_max_upload_size(),
					'hint'     => self::hint_text(),
				)
			);
		}
	}

	/**
	 * Narrow the client uploader size limit without raising Core limits.
	 *
	 * @param array $settings Settings.
	 * @return array Operation result.
	 */
	public static function plupload( array $settings ): array {
		$policy = PolicyResolver::current();
		if ( null !== $policy && null !== $policy['max_bytes'] ) {
			$settings['filters']['max_file_size'] = (string) min( wp_max_upload_size(), $policy['max_bytes'] ) . 'b';
		}
		return $settings;
	}

	/**
	 * Describe the current reader-visible effective upload limits.
	 *
	 * @return string Operation result.
	 */
	private static function hint_text(): string {
		$policy = PolicyResolver::current();
		if ( null === $policy ) {
			return __( 'UploadGo: no additional upload restrictions.', 'coocadev-uploadgo' );
		}
		return sprintf(
			/* translators: 1: maximum file size, 2: width, 3: height, 4: pixel limit. */
			__( 'UploadGo: maximum file %1$s; image width %2$s, height %3$s, pixels %4$s. Server checks apply to every supported upload.', 'coocadev-uploadgo' ),
			size_format( wp_max_upload_size(), 2 ),
			$policy['max_width'] ?? '—',
			$policy['max_height'] ?? '—',
			$policy['max_pixels'] ?? '—'
		);
	}

	/**
	 * Render escaped upload guidance on the native upload screen.
	 *
	 * @return void Operation result.
	 */
	public static function hint(): void {
		echo '<p class="ccug-hint">' . esc_html( self::hint_text() ) . '</p>';
	}

	/**
	 * Warn administrators when persisted settings cannot be safely interpreted.
	 *
	 * @return void
	 */
	public static function notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! PolicyRepository::invalid_storage() ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'UploadGo settings are missing or invalid. Only WordPress upload restrictions are active. The saved value has been preserved; restore a valid configuration before relying on UploadGo limits.', 'coocadev-uploadgo' ) . '</p></div>';
	}

	/**
	 * Render escaped controls for one global or role-specific policy.
	 *
	 * @param string $key Key.
	 * @param string $title Title.
	 * @param array  $policy Policy.
	 * @return void Operation result.
	 */
	private static function policy_fields( string $key, string $title, array $policy ): void {
		echo '<fieldset class="ccug-policy"><legend><strong>' . esc_html( $title ) . '</strong></legend><div class="ccug-limits">';
		$labels = array(
			'max_bytes'  => __( 'Maximum bytes (1 MiB = 1,048,576)', 'coocadev-uploadgo' ),
			'max_width'  => __( 'Maximum width (px)', 'coocadev-uploadgo' ),
			'max_height' => __( 'Maximum height (px)', 'coocadev-uploadgo' ),
			'max_pixels' => __( 'Maximum pixels (width × height)', 'coocadev-uploadgo' ),
		);
		foreach ( $labels as $field => $label ) {
			$id = 'ccug-' . $key . '-' . $field;
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '<input id="' . esc_attr( $id ) . '" type="number" min="1" step="1" name="policy[' . esc_attr( $key ) . '][' . esc_attr( $field ) . ']" value="' . esc_attr( (string) ( $policy[ $field ] ?? '' ) ) . '" /></label>';
		}
		echo '</div><label><input type="checkbox" name="policy[' . esc_attr( $key ) . '][types_enabled]" value="1" ' . checked( $policy['types_enabled'], true, false ) . '> ' . esc_html__( 'Restrict to selected file families', 'coocadev-uploadgo' ) . '</label><div class="ccug-types">';
		$family_labels = array(
			'images'    => __( 'Images', 'coocadev-uploadgo' ),
			'pdf'       => __( 'PDF', 'coocadev-uploadgo' ),
			'audio'     => __( 'Audio', 'coocadev-uploadgo' ),
			'video'     => __( 'Video', 'coocadev-uploadgo' ),
			'documents' => __( 'Office documents and text', 'coocadev-uploadgo' ),
		);
		foreach ( $family_labels as $family => $label ) {
			echo '<label><input type="checkbox" name="policy[' . esc_attr( $key ) . '][types][]" value="' . esc_attr( $family ) . '" ' . checked( in_array( $family, $policy['types'], true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '</div><p class="description">' . esc_html__( 'Families only narrow WordPress permissions. SVG, HTML and executable formats cannot be enabled here. Blank numeric fields add no limit.', 'coocadev-uploadgo' ) . '</p></fieldset>';
	}

	/**
	 * Render the administrator settings form, effective previews and diagnostics.
	 *
	 * @return void Operation result.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = PolicyRepository::read();
		echo '<div class="wrap ccug-settings"><h1>' . esc_html__( 'UploadGo', 'coocadev-uploadgo' ) . '</h1>';
		echo '<p>' . esc_html__( 'Global and role policies combine using the strictest limits. Administrators are included. Existing media is never changed.', 'coocadev-uploadgo' ) . '</p>';
		self::hint();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccug_save"><input type="hidden" name="revision" value="' . esc_attr( (string) $settings['config_revision'] ) . '">';
		wp_nonce_field( 'ccug_save' );
		$switches = array(
			'enabled'                  => __( 'Enable upload policies', 'coocadev-uploadgo' ),
			'background_mode'          => __( 'Apply global policy to uploads without a signed-in user (may affect importers)', 'coocadev-uploadgo' ),
			'diagnostics_enabled'      => __( 'Keep local diagnostics: reason, channel and time only; at most 100 events. Records expire after 24 hours and are removed when scheduled cleanup next runs (idle sites may be delayed).', 'coocadev-uploadgo' ),
			'remove_data_on_uninstall' => __( 'Remove UploadGo settings and diagnostics when the plugin is deleted', 'coocadev-uploadgo' ),
		);
		foreach ( $switches as $key => $label ) {
			echo '<p><label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . checked( $settings[ $key ], true, false ) . '> ' . esc_html( $label ) . '</label></p>';
		}
		self::policy_fields( 'global', __( 'Global limits', 'coocadev-uploadgo' ), $settings['global_policy'] );
		foreach ( wp_roles()->roles as $role => $details ) {
			self::policy_fields( $role, translate_user_role( $details['name'] ), $settings['role_policies'][ $role ] ?? PolicyRepository::empty_policy() );
			$preview = PolicyResolver::resolve( $settings, array( $role ), true );
			echo '<details><summary>' . esc_html__( 'Saved effective policy for this role', 'coocadev-uploadgo' ) . '</summary><pre>' . esc_html( wp_json_encode( $preview, JSON_PRETTY_PRINT ) ) . '</pre></details>';
		}
		echo '<p><label><input type="checkbox" name="confirm_empty" value="1"> ' . esc_html__( 'I understand that an enabled empty type list blocks all new files for that policy.', 'coocadev-uploadgo' ) . '</label></p>';
		submit_button( __( 'Save policies', 'coocadev-uploadgo' ) );
		echo '</form><h2>' . esc_html__( 'Local tools', 'coocadev-uploadgo' ) . '</h2>';
		foreach ( array(
			'export' => __( 'Download policy JSON', 'coocadev-uploadgo' ),
			'clear'  => __( 'Clear diagnostics', 'coocadev-uploadgo' ),
			'reset'  => __( 'Reset UploadGo settings', 'coocadev-uploadgo' ),
		) as $action => $label ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="ccug_' . esc_attr( $action ) . '"><input type="hidden" name="revision" value="' . esc_attr( (string) $settings['config_revision'] ) . '">';
			wp_nonce_field( 'ccug_' . $action );
			if ( 'reset' === $action ) {
				echo '<label><input type="checkbox" name="confirm" value="1" required> ' . esc_html__( 'Confirm reset; existing files stay unchanged.', 'coocadev-uploadgo' ) . '</label> ';
			}
			submit_button( $label, 'secondary', 'submit', false );
			echo '</form><br>';
		}
		$events = get_option( 'ccug_diagnostics', array() );
		$events = is_array( $events ) ? array_values( array_filter( $events, static fn( $event ) => is_array( $event ) && ( $event['time'] ?? 0 ) > time() - DAY_IN_SECONDS ) ) : array();
		echo '<details><summary>' . esc_html__( 'Diagnostics (reason, channel, UTC time)', 'coocadev-uploadgo' ) . '</summary><pre>' . esc_html( wp_json_encode( $events, JSON_PRETTY_PRINT ) ) . '</pre></details>';
		echo '<p class="description">' . esc_html__( 'UploadGo cannot control direct filesystem writes, unknown proxy limits or plugins that bypass WordPress upload APIs. It is not an antivirus or image compressor.', 'coocadev-uploadgo' ) . '</p></div>';
	}
}
