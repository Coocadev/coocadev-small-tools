<?php
/**
 * Versioned, optimistic-concurrency settings.
 *
 * Owns the typed configuration schema and exact-value settings CAS.
 * The authoritative read avoids a stale options cache deciding a write.
 * Malformed stored settings remain available for administrator repair;
 * the documented fallback is Core-only behavior, not a repaired policy.
 *
 * Copyright (c) 2026 Coocadev.
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * @package CoocadevUploadGo
 */

namespace Coocadev\UploadGo;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned settings validation and optimistic-concurrency storage.
 */
final class PolicyRepository {
	/**
	 * Report invalid persisted configuration without replacing its stored value.
	 *
	 * @return bool
	 */
	public static function invalid_storage(): bool {
		global $wpdb;
		$value    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccug_settings' ) );
		$settings = is_string( $value ) ? maybe_unserialize( $value ) : null;
		return ! is_array( $settings ) || is_wp_error( self::validate( $settings ) );
	}
	/**
	 * Return neutral limits that never broaden WordPress permissions.
	 *
	 * @return array Operation result.
	 */
	public static function empty_policy(): array {
		return array(
			'max_bytes'     => null,
			'max_width'     => null,
			'max_height'    => null,
			'max_pixels'    => null,
			'types_enabled' => false,
			'types'         => array(),
		);
	}

	/**
	 * Return the disabled first-install configuration.
	 *
	 * @return array Operation result.
	 */
	public static function defaults(): array {
		return array(
			'schema_version'           => 1,
			'config_revision'          => 1,
			'enabled'                  => false,
			'global_policy'            => self::empty_policy(),
			'role_policies'            => array(),
			'background_mode'          => false,
			'diagnostics_enabled'      => false,
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Read the authoritative stored policy, falling back explicitly to Core-only behavior if invalid.
	 *
	 * @return array Operation result.
	 */
	public static function read(): array {
		global $wpdb;
		$value    = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccug_settings' ) );
		$settings = is_string( $value ) ? maybe_unserialize( $value ) : null;
		if ( ! is_array( $settings ) || 1 !== ( $settings['schema_version'] ?? null ) ) {
			return self::defaults();
		}
		$validated = self::validate( $settings );
		return is_wp_error( $validated ) ? self::defaults() : $validated;
	}

	/**
	 * Validate the versioned configuration and every role policy.
	 *
	 * @param array $settings Settings.
	 * @return array|\WP_Error Operation result.
	 */
	public static function validate( array $settings ) {
		$allowed = array_keys( self::defaults() );
		if ( array_diff( array_keys( $settings ), $allowed ) ) {
			return new \WP_Error( 'ccug_invalid', __( 'Unknown policy setting.', 'coocadev-uploadgo' ) );
		}
		$result = self::defaults();
		foreach ( array( 'enabled', 'background_mode', 'diagnostics_enabled', 'remove_data_on_uninstall' ) as $key ) {
			if ( ! isset( $settings[ $key ] ) || ! is_bool( $settings[ $key ] ) ) {
				return new \WP_Error( 'ccug_invalid', __( 'Policy switches must be true or false.', 'coocadev-uploadgo' ) );
			}
			$result[ $key ] = $settings[ $key ];
		}
		if ( 1 !== ( $settings['schema_version'] ?? null ) || ! is_int( $settings['config_revision'] ?? null ) || $settings['config_revision'] < 1 ) {
			return new \WP_Error( 'ccug_invalid', __( 'Invalid policy version.', 'coocadev-uploadgo' ) );
		}
		$result['config_revision'] = $settings['config_revision'];
		$policy                    = self::validate_policy( $settings['global_policy'] ?? null );
		if ( is_wp_error( $policy ) ) {
			return $policy;
		}
		$result['global_policy'] = $policy;
		if ( ! is_array( $settings['role_policies'] ?? null ) || count( $settings['role_policies'] ) > 100 ) {
			return new \WP_Error( 'ccug_invalid', __( 'Invalid role policies.', 'coocadev-uploadgo' ) );
		}
		foreach ( $settings['role_policies'] as $role => $value ) {
			if ( ! is_string( $role ) || sanitize_key( $role ) !== $role || strlen( $role ) > 191 ) {
				return new \WP_Error( 'ccug_invalid', __( 'Invalid role identifier.', 'coocadev-uploadgo' ) );
			}
			$policy = self::validate_policy( $value );
			if ( is_wp_error( $policy ) ) {
				return $policy;
			}
			$result['role_policies'][ $role ] = $policy;
		}
		return $result;
	}

	/**
	 * Validate positive numeric bounds and explicit file-family selections.
	 *
	 * @param mixed $input Input.
	 * @return array|\WP_Error Operation result.
	 */
	public static function validate_policy( $input ) {
		if ( ! is_array( $input ) || array_diff( array_keys( $input ), array_keys( self::empty_policy() ) ) ) {
			return new \WP_Error( 'ccug_invalid', __( 'Invalid upload policy.', 'coocadev-uploadgo' ) );
		}
		$result = self::empty_policy();
		$bounds = array(
			'max_bytes'  => 2147483647,
			'max_width'  => 100000,
			'max_height' => 100000,
			'max_pixels' => 1000000000,
		);
		foreach ( $bounds as $key => $maximum ) {
			$value = $input[ $key ] ?? null;
			if ( null !== $value && ( ! is_int( $value ) || $value < 1 || $value > $maximum ) ) {
				return new \WP_Error( 'ccug_invalid', __( 'Limits must be positive whole numbers, or blank for no additional limit.', 'coocadev-uploadgo' ) );
			}
			$result[ $key ] = $value;
		}
		if ( ! is_bool( $input['types_enabled'] ?? null ) || ! is_array( $input['types'] ?? null ) ) {
			return new \WP_Error( 'ccug_invalid', __( 'Invalid file type policy.', 'coocadev-uploadgo' ) );
		}
		$result['types_enabled'] = $input['types_enabled'];
		foreach ( $input['types'] as $type ) {
			if ( ! is_string( $type ) || ! array_key_exists( $type, PolicyResolver::families() ) ) {
				return new \WP_Error( 'ccug_invalid', __( 'Unrecognized file type family.', 'coocadev-uploadgo' ) );
			}
		}
		$result['types'] = array_values( array_unique( $input['types'] ) );
		return $result;
	}

	/**
	 * Replace only the exact previous stored revision to prevent lost settings updates.
	 *
	 * @param array $settings Settings.
	 * @param int   $revision Revision.
	 * @return array|\WP_Error Operation result.
	 */
	public static function save( array $settings, int $revision ) {
		global $wpdb;
		$validated = self::validate( $settings );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}
		$old      = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s', $wpdb->options, 'ccug_settings' ) );
		$previous = is_string( $old ) ? maybe_unserialize( $old ) : null;
		if ( ! is_array( $previous ) || ( $previous['config_revision'] ?? 0 ) !== $revision ) {
			return new \WP_Error( 'ccug_conflict', __( 'These settings changed in another tab. Reload before saving.', 'coocadev-uploadgo' ) );
		}
		$validated['config_revision'] = $revision + 1;
		$changed                      = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s AND BINARY option_value = BINARY %s', $wpdb->options, maybe_serialize( $validated ), 'ccug_settings', $old ) );
		if ( 1 !== $changed ) {
			return new \WP_Error( 'ccug_conflict', __( 'Settings were not saved. Reload and try again.', 'coocadev-uploadgo' ) );
		}
		wp_cache_delete( 'ccug_settings', 'options' );
		return $validated;
	}
}
